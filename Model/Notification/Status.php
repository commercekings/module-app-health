<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Api\Data\StatusReport;
use CommerceKings\AppHealth\Api\Data\TeamMessageContent;
use CommerceKings\AppHealth\Api\StatusPolicyInterface;
use CommerceKings\AppHealth\Model\CheckPool;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Message\RendererPool;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\StateStore;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Notification type "app_health_status", reference = the scope it goes to: 0, the default scope's recipient (or, with
 * shop-wide alerts off, the first website's with alerts on) - the checks of all websites - through the same queue,
 * consumer and channel as the alerts - so a status that stops coming means the sending path is broken (the heartbeat
 * stops too: Runner pings only while the last status went out). A line for the checks ("All checks OK" or the
 * problems), one for the warnings (not sent at once), then the log errors since the last status (ERROR entries: they
 * don't alert one by one).
 *
 * Every interval it is queued and delivered; it is posted to the channel only when the policy
 * (Api\StatusPolicyInterface; shipped: Model\Status\DefaultPolicy) says so, else delivered quietly - recorded as
 * sent, the channel not called - never while an App health message is failing to go out (TeamMessage::isFailing(),
 * or this status's own failed sends): then it is posted, and only that real post proves the channel again. A quiet
 * delivery still proves the queue and its consumer work (the heartbeat), not the channel itself: the posted statuses
 * (at least the daily "All checks OK") and the alerts do. "status:posted" keeps when it was last posted, and last
 * posted as all OK, per scope.
 */
class Status implements NotificationTypeInterface
{
    public const CODE = 'app_health_status';
    /** Log errors listed, the most frequent first. */
    public const ERRORS = 5;
    /** A known error (seen before) is listed again when it comes this often in an interval. */
    public const ERROR_THRESHOLD = 10;
    public const POSTED_KEY = 'status:posted';
    /** The interval, whatever the policy says. */
    public const MIN_MINUTES = 15;
    public const MAX_MINUTES = 180;

    public function __construct(
        private readonly TeamMessage $teamMessage,
        private readonly StateStore $store,
        private readonly Messages $messages,
        private readonly CheckPool $pool,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly StatusPolicyInterface $policy,
        private readonly RendererPool $renderers
    ) {
    }

    /** The policy's interval for the scope, within MIN_MINUTES and MAX_MINUTES (errors:recent keeps 3 hours). */
    public static function intervalMinutes(StatusPolicyInterface $policy, int $scopeId): int
    {
        return min(self::MAX_MINUTES, max(self::MIN_MINUTES, $policy->getIntervalMinutes($scopeId)));
    }

    public function process(string $reference): void
    {
        $this->teamMessage->deliver(self::CODE, $reference, function (Target $target, array $row): ?array {
            if (!$this->config->isHourlyStatusEnabled()) {
                return null;
            }
            $now = $this->dateTime->gmtTimestamp();
            $sentAt = (string)($row['sent_at'] ?? '');
            $minutes = self::intervalMinutes($this->policy, $target->websiteId);
            if ($sentAt !== '' && (int)strtotime($sentAt . ' UTC') > $now - ($minutes - 2) * 60) {
                return null; // a late copy of the queue message: one was sent since it was queued (as Runner: -2 min)
            }
            $from = $sentAt !== '' ? (int)strtotime($sentAt . ' UTC') : $now - $minutes * 60;
            $rows = $this->store->load(['checks', Fingerprints::KEY, Fingerprints::ERRORS_KEY, self::POSTED_KEY]);
            $posted = (array)($rows[self::POSTED_KEY] ?? []);
            $mine = (array)($posted[$target->websiteId] ?? []);
            // only what the last posted status did not say: a check not OK then is "still open"; an error new or
            // frequent (what a posted status told, the daily summary has)
            $told = array_flip(array_map('strval', (array)($mine['open'] ?? [])));
            $parts = $this->parts((array)($rows['checks'] ?? []), (array)($rows[Fingerprints::KEY] ?? []),
                (array)($rows[Fingerprints::ERRORS_KEY] ?? []), $from,
                fn (int $count, bool $new) => $new || $count >= self::ERROR_THRESHOLD);
            $open = array_keys($parts['problems'] + array_column($parts['warnings'], 0, 2));
            $parts['known'] = array_values(array_intersect_key($parts['problems'] + array_column($parts['warnings'], 0,
                2), $told));
            $parts['problems'] = array_diff_key($parts['problems'], $told);
            $parts['warnings'] = array_values(array_filter($parts['warnings'], fn ($w) => !isset($told[$w[2]])));
            $report = new StatusReport($target->websiteId, $this->localNow($target, $now),
                array_values($parts['problems']), array_column($parts['warnings'], 1, 0), $parts['errorCount'],
                array_map(fn ($e) => ['label' => $e[0], 'count' => $e[1], 'new' => $e[2]], $parts['errors']),
                isset($mine['at']) ? (int)$mine['at'] : null, isset($mine['ok_at']) ? (int)$mine['ok_at'] : null,
                $parts['known']);
            // after a failed send (any App health message), only a real post proves the channel works again
            $post = (int)($row['failed_sends'] ?? 0) > 0 || $this->teamMessage->isFailing()
                || $this->policy->shouldPost($report);
            $text = $this->text($target, $parts);
            return [$text, function () use ($post, $report, $posted, $now, $target, $open, $mine): void {
                if ($post) {
                    $posted[$target->websiteId] = ['at' => $now, 'open' => $open]
                        + ($report->isAllOk() ? ['ok_at' => $now] : array_intersect_key($mine, ['ok_at' => 1]));
                    $this->store->save([self::POSTED_KEY => $posted]);
                    return;
                }
                // quiet: nothing new was told, but what is no longer open is forgotten - back later, it is new
                $still = array_values(array_intersect(array_map('strval', (array)($mine['open'] ?? [])),
                    array_map('strval', $open)));
                if ($still !== array_values(array_map('strval', (array)($mine['open'] ?? [])))) {
                    $posted[$target->websiteId] = ['open' => $still] + $mine;
                    $this->store->save([self::POSTED_KEY => $posted]);
                }
            }, $post];
        });
    }

    /**
     * What a status says, from the "checks" row (by scope and code) and the log errors (the "fingerprints" and
     * "errors:recent" rows) since $from - for the hourly status and for "Send status now" (a snapshot's rows).
     *
     * @param array<int|string, array<string, array<mixed>>> $checks
     * @param array<string, array<mixed>> $fingerprints
     * @param list<array<int, mixed>> $recentErrors
     * @param (\Closure(int, bool): bool)|null $keepError which errors to list: count since $from, first seen then
     * @return array{problems: array<string, string>, warnings: list<array{0: string, 1: string, 2: string}>,
     *     idle: list<array{0: string, 1: string}>, errorCount: int, errors: list<array{0: string, 1: int, 2: bool}>,
     *     moreErrors: int}
     */
    public function parts(array $checks, array $fingerprints, array $recentErrors, int $from,
        ?\Closure $keepError = null): array
    {
        $problems = [];
        $warnings = [];
        $idle = [];
        foreach ($checks as $byCode) {
            foreach ((array)$byCode as $code => $check) {
                $status = $check['status'] ?? CheckResult::OK;
                if (!empty($check['idle'])) {
                    $idle[(string)$code] ??= [$this->pool->getLabel((string)$code), (string)($check['detail'] ?? '')];
                } elseif ($status === CheckResult::FAIL) {
                    $problems[(string)$code] = $this->pool->getLabel((string)$code);
                } elseif ($status === CheckResult::WARN) {
                    $warnings[(string)$code] ??= [$this->pool->getLabel((string)$code),
                        (string)($check['detail'] ?? ''), (string)$code];
                }
            }
        }
        $byFingerprint = [];
        foreach ($recentErrors as [$time, $fp, $count, $new]) {
            if ((int)$time > $from) {
                $byFingerprint[$fp] ??= ['count' => 0, 'new' => false];
                $byFingerprint[$fp]['count'] += (int)$count;
                $byFingerprint[$fp]['new'] = $byFingerprint[$fp]['new'] || (bool)$new;
            }
        }
        if ($keepError !== null) {
            $byFingerprint = array_filter($byFingerprint, fn ($e) => $keepError($e['count'], $e['new']));
        }
        uasort($byFingerprint, fn ($a, $b) => $b['count'] <=> $a['count']);
        $errors = [];
        foreach (array_slice($byFingerprint, 0, self::ERRORS, true) as $fp => $error) {
            $errors[] = [Fingerprints::name((array)($fingerprints[$fp] ?? ['label' => '#' . $fp])), $error['count'],
                $error['new']];
        }
        return ['problems' => $problems, 'warnings' => array_values($warnings),
            'idle' => array_values($idle), 'errorCount' => array_sum(array_column($byFingerprint, 'count')),
            'errors' => $errors, 'moreErrors' => count($byFingerprint) - count($errors)];
    }

    /** @param array<string, mixed> $parts parts() */
    public function content(Target $target, array $parts, string $heading = 'Hourly status'): TeamMessageContent
    {
        return $this->messages->status($target, array_values($parts['problems']), $parts['warnings'],
            $parts['errorCount'], $parts['errors'], $parts['moreErrors'], $parts['idle'], $heading,
            (array)($parts['known'] ?? []));
    }

    /**
     * The status as its channel's text.
     *
     * @param array<string, mixed> $parts parts()
     */
    public function text(Target $target, array $parts, string $heading = 'Hourly status'): string
    {
        return $this->renderers->render($target->channel, $this->content($target, $parts, $heading));
    }

    private function localNow(Target $target, int $now): \DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone($target->timezone !== '' ? $target->timezone : 'UTC');
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        return (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
    }
}
