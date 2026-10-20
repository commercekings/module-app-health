<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Model\SiteDomain;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Psr\Log\LoggerInterface;

/**
 * A team message per scope (an alert, the daily summary) through Notification's queue, sent once per period:
 * Notification's delivery state ("<type>", the scope: 0 = shop-wide, else a website id) holds the queue mark, the
 * last send, the retry time and the failures in a row.
 *
 * queue() marks the website "queued" in the statement that checks it is free (not queued, or queued for over
 * STUCK_MINUTES; no retry pending) and publishes "<type>|<scope>". deliver(), in the consumer, composes the text on
 * fresh state, claims the delivery version and sends it as a service message: no cooldown, no stop list, no quiet hours
 * - or, when the type says so, delivers it quietly: recorded as sent, the channel not called (the hourly status with
 * nothing to say). A provider's temporary refusal waits its Retry-After (within bounds); any other failure waits longer
 * each time (RETRY_MINUTES, then the last one again - a team alert is never given up), so a channel outage retries
 * until it ends. Only the type, the scope and exception classes are logged, never the text or the recipient.
 */
class TeamMessage
{
    public const STUCK_MINUTES = 60;
    /** A claim not finished after this (the worker stopped, maybe after the provider took it): maybe sent. */
    public const LEASE_MINUTES = 10;
    public const RETRY_MINUTES = [5, 15, 30, 60];
    public const TEMPORARY_MIN_SECONDS = 60;
    public const TEMPORARY_MAX_SECONDS = 3600;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $deliveryState,
        private readonly QueueInterface $queue,
        private readonly SenderInterface $sender,
        private readonly Targets $targets,
        private readonly LoggerInterface $logger,
        private readonly SiteDomain $siteDomain
    ) {
    }

    /**
     * @param string $condition a trusted SQL condition the state row must also meet, e.g. "not sent since 08:00"
     * @return bool whether this call queued it
     */
    public function queue(string $type, int $websiteId, string $condition = ''): bool
    {
        $db = $this->resource->getConnection();
        $this->deliveryState->ensure($type, $websiteId);
        // a claim not finished (odd version) past its lease: queued again, so a worker comes and takes it over
        $where = $this->deliveryState->keyCondition($type, $websiteId) + [
            '(queued_at IS NULL OR queued_at < NOW() - INTERVAL ' . self::STUCK_MINUTES . ' MINUTE'
                . ' OR (delivery_version % 2 = 1 AND updated_at < NOW() - INTERVAL ' . self::LEASE_MINUTES
                . ' MINUTE))',
            '(skip_until IS NULL OR skip_until <= NOW())',
        ];
        if ($condition !== '') {
            $where[] = $condition;
        }
        if (!$db->update($this->deliveryState->getTableName(), ['queued_at' => new Expression('NOW()')]
            + self::keepClaimTime(), $where)) {
            return false;
        }
        try {
            $this->queue->publish($type, (string)$websiteId);
        } catch (\Throwable $error) {
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null] + self::keepClaimTime(),
                $this->deliveryState->keyCondition($type, $websiteId));
            throw $error;
        }
        return true;
    }

    /**
     * An odd row's updated_at is its claim's time (the lease): a mark set or cleared must not move it (the column is
     * ON UPDATE CURRENT_TIMESTAMP, unless the statement sets it).
     *
     * @return array<string, Expression>
     */
    private static function keepClaimTime(): array
    {
        return ['updated_at' => new Expression('IF(delivery_version % 2 = 1, updated_at, NOW())')];
    }

    /**
     * @param \Closure(Target, array<string, mixed>): ?array{0: string, 1: \Closure(): void, 2?: bool} $compose the
     *     text, what to record once it is out and whether to post it (false: delivered quietly - recorded as sent, the
     *     channel not called), or null when there is nothing to send (anymore)
     * @return bool whether it was sent (or may have been: MessageSentException)
     */
    public function deliver(string $type, string $reference, \Closure $compose): bool
    {
        $websiteId = ctype_digit($reference) ? (int)$reference : 0;
        $row = $this->deliveryState->load($type, $websiteId);
        if (!$this->siteDomain->matches()) {
            // a copy's queue can hold the original's messages (a database copy): dropped, never sent from here
            $this->release($type, $websiteId, $row['queued_at'] ?? null);
            $this->logger->info(sprintf('CommerceKings_AppHealth: %s for scope %d dropped: this looks like a copy'
                . ' of %s', $type, $websiteId, $this->siteDomain->stored()));
            return false;
        }
        $queuedAt = $row['queued_at'] ?? null;
        $db = $this->resource->getConnection();
        $key = $this->deliveryState->keyCondition($type, $websiteId);
        $version = (int)($row['delivery_version'] ?? 0);
        if ($version % 2 === 1) {
            // claimed and not finished: another worker is sending it - or stopped, maybe after the provider took it
            if (!$this->takeOver($type, $websiteId, $version, $key)) {
                return false;
            }
            $version++; // even again, ours: what is due now goes out as usual
        }
        $target = $row !== null ? $this->targets->forWebsite($websiteId) : null;
        $composed = $target !== null ? $compose($target, $row) : null;
        if ($composed === null) {
            // nothing to send (any more), or nowhere to: its failures are no reason to hold the heartbeat
            $this->release($type, $websiteId, $queuedAt, true);
            return false;
        }
        [$text, $sent] = $composed;
        $post = $composed[2] ?? true;
        // one worker per queued mark, exclusive while it sends: the claim makes the version odd - a copy of the
        // message or a late one, before or after it, finds it raised or odd - and every outcome makes it even again
        $claimed = $db->update($this->deliveryState->getTableName(), ['delivery_version' => $version + 1],
            $key + ['delivery_version = ?' => $version, 'queued_at <=> ?' => $queuedAt ?? new Expression('NULL')]);
        if (!$claimed) {
            return false;
        }
        $mine = $key + ['delivery_version = ?' => $version + 1];
        $done = ['delivery_version' => $version + 2];
        if (!$post) {
            $this->recordSent($sent, $mine, $done);
            $this->logger->info(sprintf('CommerceKings_AppHealth: %s for scope %d delivered quietly (not posted)',
                $type, $websiteId));
            return true;
        }
        try {
            $status = $this->sender->send(Delivery::text($target->storeId, $target->channel, $target->recipient,
                fn () => new DeliveryContent([], null, null, $text), useCooldown: false, stoppable: false,
                type: $type));
        } catch (MessageSentException $e) {
            // it is out, or may be (the provider can't tell): never again - a missed alert beats a duplicate
            $this->logger->warning(sprintf('CommerceKings_AppHealth: %s for scope %d may have been sent (%s);'
                . ' not sent again', $type, $websiteId, get_class($e->getPrevious() ?? $e)));
            $this->recordSent($sent, $mine, $done);
            return true;
        } catch (TemporaryFailureException $e) {
            $wait = min(self::TEMPORARY_MAX_SECONDS, max(self::TEMPORARY_MIN_SECONDS,
                $e->getRetryAfter() ?? self::RETRY_MINUTES[0] * 60));
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null,
                'skip_until' => new Expression('NOW() + INTERVAL ' . $wait . ' SECOND')] + $done, $mine);
            $this->logger->warning(sprintf('CommerceKings_AppHealth: %s for scope %d not sent for now, next try in'
                . ' %d seconds', $type, $websiteId, $wait));
            return false;
        } catch (\Throwable $e) {
            $failed = (int)$row['failed_sends'] + 1;
            $wait = self::RETRY_MINUTES[min($failed, count(self::RETRY_MINUTES)) - 1];
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null, 'failed_sends' => $failed,
                'skip_until' => new Expression('NOW() + INTERVAL ' . $wait . ' MINUTE')] + $done, $mine);
            if ($e instanceof \InvalidArgumentException) {
                // not an address the channel takes: a setting to fix, not a reason for the consumer to reject
                $this->logger->error(sprintf('CommerceKings_AppHealth: %s for scope %d not sent: the recipient is'
                    . ' not valid for the "%s" channel (App health > Recipient)', $type, $websiteId, $target->channel));
                return false;
            }
            $this->logger->warning(sprintf('CommerceKings_AppHealth: %s for scope %d failed (%d in a row, %s),'
                . ' next try in %d minutes', $type, $websiteId, $failed, self::reason($e), $wait));
            throw $e;
        }
        if ($status !== SenderInterface::SENT) {
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null] + $done, $mine);
            $this->logger->info(sprintf('CommerceKings_AppHealth: %s for scope %d not sent (%s)', $type, $websiteId,
                $status));
            return false;
        }
        $this->recordSent($sent, $mine, $done);
        return true;
    }

    /**
     * A claim not finished: within LEASE_MINUTES (its updated_at) another worker is sending it - nothing to do;
     * after, it stopped, maybe after the provider took the message: one worker takes it over in one statement and
     * delivers what is due now - maybe a second time, but never a newer message recorded as told without a post.
     *
     * @param array<int|string, mixed> $key
     * @return bool whether this worker took it over (the version is $version + 1, even)
     */
    private function takeOver(string $type, int $websiteId, int $version, array $key): bool
    {
        $taken = $this->resource->getConnection()->update($this->deliveryState->getTableName(),
            ['delivery_version' => $version + 1], $key + ['delivery_version = ?' => $version,
                'updated_at < NOW() - INTERVAL ' . self::LEASE_MINUTES . ' MINUTE']);
        if (!$taken) {
            return false;
        }
        $this->logger->warning(sprintf('CommerceKings_AppHealth: %s for scope %d: a delivery claimed over %d minutes'
            . ' ago never finished (it may have been sent); sending what is due now', $type, $websiteId,
            self::LEASE_MINUTES));
        return true;
    }

    /**
     * What was told (the type's record) and "sent" in one transaction: a crash between them can't leave it told but
     * not sent - or sent but not told - and post it again.
     *
     * @param array<int|string, mixed> $mine
     * @param array<string, int> $done the claim's end (an even version), [] when it is even already
     */
    private function recordSent(\Closure $sent, array $mine, array $done): void
    {
        $db = $this->resource->getConnection();
        $db->beginTransaction();
        try {
            $sent();
            $this->markSent($mine, $done);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * What to log of a failure: its class, and a provider's error code when its message names one the agreed way
     * ("Slack refused the message (channel_not_found): ...") - never the rest of the message, which may name the
     * recipient.
     */
    private static function reason(\Throwable $e): string
    {
        $named = preg_match('/^\w+ refused the message \(([a-z][a-z0-9_]{1,40})\)/', $e->getMessage(), $m);
        return get_class($e) . ($named ? ', ' . $m[1] : '');
    }

    /**
     * Whether an App health message is failing to go out: a delivery row of an app_health_* type with failures in a
     * row, or a retry waiting (e.g. a revoked token, a recipient the channel no longer takes) - until a send of it
     * succeeds (markSent()). One query, on the key's type prefix.
     */
    public function isFailing(): bool
    {
        $db = $this->resource->getConnection();
        // only a scope that still has somewhere to send: a website deleted or its channel removed leaves a row behind;
        // a type switched off (summaries) stops touching its row, which ages out after a day
        $scopes = $db->fetchCol($db->select()->from($this->deliveryState->getTableName(), ['reference_id'])
            ->where('type LIKE ?', 'app\\_health\\_%')
            ->where('(failed_sends > 0 OR skip_until > NOW())')
            ->where('updated_at > NOW() - INTERVAL 1 DAY')->group('reference_id')->limit(20));
        foreach ($scopes as $websiteId) {
            if ($this->targets->forWebsite((int)$websiteId) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int|string, mixed> $mine
     * @param array<string, int> $done
     */
    private function markSent(array $mine, array $done): void
    {
        $this->resource->getConnection()->update($this->deliveryState->getTableName(),
            ['sent_at' => new Expression('NOW()'), 'queued_at' => null, 'failed_sends' => 0, 'skip_until' => null]
            + $done, $mine);
    }

    /** Nothing to send for this message: clear the mark it was queued with (a newer one stays). */
    /** @param bool $forget no target any more: its failures are not a reason to hold the heartbeat */
    private function release(string $type, int $websiteId, ?string $queuedAt, bool $forget = false): void
    {
        if ($queuedAt !== null || $forget) {
            $this->resource->getConnection()->update($this->deliveryState->getTableName(), ['queued_at' => null]
                + ($forget ? ['failed_sends' => 0, 'skip_until' => null] : []) + self::keepClaimTime(),
                $this->deliveryState->keyCondition($type, $websiteId)
                + ($queuedAt !== null ? ['queued_at = ?' => $queuedAt] : []));
        }
    }
}
