<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Api\Data\StatusReport;
use CommerceKings\AppHealth\Api\StatusPolicyInterface;
use CommerceKings\AppHealth\Model\CheckPool;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
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
 * sent, the channel not called. A quiet delivery still proves the queue and its consumer work (the heartbeat), not
 * the channel itself: the posted statuses (at least the daily "All checks OK") and the alerts do. "status:posted"
 * keeps when it was last posted, and last posted as all OK, per scope.
 */
class Status implements NotificationTypeInterface
{
    public const CODE = 'app_health_status';
    /** Log errors listed, the most frequent first. */
    public const ERRORS = 5;
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
        private readonly StatusPolicyInterface $policy
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
            $from = $sentAt !== '' ? (int)strtotime($sentAt . ' UTC')
                : $now - self::intervalMinutes($this->policy, $target->websiteId) * 60;
            $rows = $this->store->load(['checks', Fingerprints::KEY, Fingerprints::ERRORS_KEY, self::POSTED_KEY]);
            $problems = [];
            $warnings = [];
            $idle = [];
            foreach ((array)($rows['checks'] ?? []) as $checks) {
                foreach ((array)$checks as $code => $check) {
                    $status = $check['status'] ?? CheckResult::OK;
                    if (!empty($check['idle'])) {
                        $idle[(string)$code] ??= [$this->pool->getLabel((string)$code),
                            (string)($check['detail'] ?? '')];
                    } elseif ($status === CheckResult::FAIL) {
                        $problems[(string)$code] = $this->pool->getLabel((string)$code);
                    } elseif ($status === CheckResult::WARN) {
                        $warnings[(string)$code] ??= [$this->pool->getLabel((string)$code),
                            (string)($check['detail'] ?? '')];
                    }
                }
            }
            $byFingerprint = [];
            foreach ((array)($rows[Fingerprints::ERRORS_KEY] ?? []) as [$time, $fp, $count, $new]) {
                if ((int)$time > $from) {
                    $byFingerprint[$fp] ??= ['count' => 0, 'new' => false];
                    $byFingerprint[$fp]['count'] += (int)$count;
                    $byFingerprint[$fp]['new'] = $byFingerprint[$fp]['new'] || (bool)$new;
                }
            }
            uasort($byFingerprint, fn ($a, $b) => $b['count'] <=> $a['count']);
            $known = (array)($rows[Fingerprints::KEY] ?? []);
            $errors = [];
            foreach (array_slice($byFingerprint, 0, self::ERRORS, true) as $fp => $error) {
                $errors[] = [Fingerprints::name((array)($known[$fp] ?? ['label' => '#' . $fp])), $error['count'],
                    $error['new']];
            }
            $errorCount = array_sum(array_column($byFingerprint, 'count'));
            $posted = (array)($rows[self::POSTED_KEY] ?? []);
            $mine = (array)($posted[$target->websiteId] ?? []);
            $report = new StatusReport($target->websiteId, $this->localNow($target, $now), array_values($problems),
                array_column($warnings, 1, 0), $errorCount,
                array_map(fn ($e) => ['label' => $e[0], 'count' => $e[1], 'new' => $e[2]], $errors),
                isset($mine['at']) ? (int)$mine['at'] : null, isset($mine['ok_at']) ? (int)$mine['ok_at'] : null);
            $post = $this->policy->shouldPost($report);
            $text = $this->messages->status($target, array_values($problems), array_values($warnings), $errorCount,
                $errors, count($byFingerprint) - count($errors), array_values($idle));
            return [$text, function () use ($post, $report, $posted, $mine, $now, $target): void {
                if ($post) {
                    $posted[$target->websiteId] = ['at' => $now] + ($report->isAllOk() ? ['ok_at' => $now] : $mine);
                    $this->store->save([self::POSTED_KEY => $posted]);
                }
            }, $post];
        });
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
