<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

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
 * STUCK_MINUTES; no retry pending) and publishes "<type>|<scope>". deliver(), in the consumer, composes the text
 * on fresh state, claims the delivery version and sends it as a service message: no cooldown, no stop list, no quiet
 * hours. A provider's temporary refusal waits its Retry-After (within bounds); any other failure waits longer each
 * time (RETRY_MINUTES, then the last one again - a team alert is never given up), so a channel outage retries until
 * it ends. Only the type, the scope and exception classes are logged, never the text or the recipient.
 */
class TeamMessage
{
    public const STUCK_MINUTES = 60;
    public const RETRY_MINUTES = [5, 15, 30, 60];
    public const TEMPORARY_MIN_SECONDS = 60;
    public const TEMPORARY_MAX_SECONDS = 3600;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $deliveryState,
        private readonly QueueInterface $queue,
        private readonly SenderInterface $sender,
        private readonly Targets $targets,
        private readonly LoggerInterface $logger
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
        $where = $this->deliveryState->keyCondition($type, $websiteId) + [
            '(queued_at IS NULL OR queued_at < NOW() - INTERVAL ' . self::STUCK_MINUTES . ' MINUTE)',
            '(skip_until IS NULL OR skip_until <= NOW())',
        ];
        if ($condition !== '') {
            $where[] = $condition;
        }
        if (!$db->update($this->deliveryState->getTableName(), ['queued_at' => new Expression('NOW()')], $where)) {
            return false;
        }
        try {
            $this->queue->publish($type, (string)$websiteId);
        } catch (\Throwable $error) {
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null],
                $this->deliveryState->keyCondition($type, $websiteId));
            throw $error;
        }
        return true;
    }

    /**
     * @param \Closure(Target, array<string, mixed>): ?array{0: string, 1: \Closure(): void} $compose the text and what
     *     to record once it is out, or null when there is nothing to send (anymore)
     * @return bool whether it was sent (or may have been: MessageSentException)
     */
    public function deliver(string $type, string $reference, \Closure $compose): bool
    {
        $websiteId = ctype_digit($reference) ? (int)$reference : 0;
        $row = $this->deliveryState->load($type, $websiteId);
        $queuedAt = $row['queued_at'] ?? null;
        $target = $row !== null ? $this->targets->forWebsite($websiteId) : null;
        $composed = $target !== null ? $compose($target, $row) : null;
        if ($composed === null) {
            $this->release($type, $websiteId, $queuedAt);
            return false;
        }
        [$text, $sent] = $composed;
        $db = $this->resource->getConnection();
        $version = (int)$row['delivery_version'] + 1;
        $key = $this->deliveryState->keyCondition($type, $websiteId);
        // one worker per queued mark: a copy of the message or a late one finds the version raised
        $claimed = $db->update($this->deliveryState->getTableName(), ['delivery_version' => $version],
            $key + ['delivery_version = ?' => $version - 1,
                'queued_at <=> ?' => $queuedAt ?? new Expression('NULL')]);
        if (!$claimed) {
            return false;
        }
        $mine = $key + ['delivery_version = ?' => $version];
        try {
            $status = $this->sender->send(Delivery::text($target->storeId, $target->channel, $target->recipient,
                fn () => new DeliveryContent([], null, null, $text), useCooldown: false, stoppable: false,
                type: $type));
        } catch (MessageSentException $e) {
            // it is out, or may be (the provider can't tell): never again - a missed alert beats a duplicate
            $this->logger->warning(sprintf('CommerceKings_AppHealth: %s for scope %d may have been sent (%s);'
                . ' not sent again', $type, $websiteId, get_class($e->getPrevious() ?? $e)));
            $sent();
            $this->markSent($mine);
            return true;
        } catch (TemporaryFailureException $e) {
            $wait = min(self::TEMPORARY_MAX_SECONDS, max(self::TEMPORARY_MIN_SECONDS,
                $e->getRetryAfter() ?? self::RETRY_MINUTES[0] * 60));
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null,
                'skip_until' => new Expression('NOW() + INTERVAL ' . $wait . ' SECOND')], $mine);
            $this->logger->warning(sprintf('CommerceKings_AppHealth: %s for scope %d not sent for now, next try in'
                . ' %d seconds', $type, $websiteId, $wait));
            return false;
        } catch (\Throwable $e) {
            $failed = (int)$row['failed_sends'] + 1;
            $wait = self::RETRY_MINUTES[min($failed, count(self::RETRY_MINUTES)) - 1];
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null, 'failed_sends' => $failed,
                'skip_until' => new Expression('NOW() + INTERVAL ' . $wait . ' MINUTE')], $mine);
            if ($e instanceof \InvalidArgumentException) {
                // not an address the channel takes: a setting to fix, not a reason for the consumer to reject
                $this->logger->error(sprintf('CommerceKings_AppHealth: %s for scope %d not sent: the recipient is'
                    . ' not valid for the "%s" channel (App health > Recipient)', $type, $websiteId, $target->channel));
                return false;
            }
            $this->logger->warning(sprintf('CommerceKings_AppHealth: %s for scope %d failed (%d in a row, %s),'
                . ' next try in %d minutes', $type, $websiteId, $failed, get_class($e), $wait));
            throw $e;
        }
        if ($status !== SenderInterface::SENT) {
            $db->update($this->deliveryState->getTableName(), ['queued_at' => null], $mine);
            $this->logger->info(sprintf('CommerceKings_AppHealth: %s for scope %d not sent (%s)', $type, $websiteId,
                $status));
            return false;
        }
        $sent();
        $this->markSent($mine);
        return true;
    }

    /** @param array<int|string, mixed> $mine */
    private function markSent(array $mine): void
    {
        $this->resource->getConnection()->update($this->deliveryState->getTableName(),
            ['sent_at' => new Expression('NOW()'), 'queued_at' => null, 'failed_sends' => 0, 'skip_until' => null],
            $mine);
    }

    /** Nothing to send for this message: clear the mark it was queued with (a newer one stays). */
    private function release(string $type, int $websiteId, ?string $queuedAt): void
    {
        if ($queuedAt !== null) {
            $this->resource->getConnection()->update($this->deliveryState->getTableName(), ['queued_at' => null],
                $this->deliveryState->keyCondition($type, $websiteId) + ['queued_at = ?' => $queuedAt]);
        }
    }
}
