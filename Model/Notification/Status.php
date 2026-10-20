<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\CheckPool;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\StateStore;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Notification type "app_health_status", reference 0: the hourly status, to the default scope's recipient, through the
 * same queue, consumer and channel as the alerts - so a status that stops coming means the sending path is broken
 * (the heartbeat stops too: Runner pings only while the last status went out). A line for the checks ("All checks
 * OK" or the problems), one for the warnings (not sent at once), then the log errors since the last status (ERROR
 * entries: they don't alert one by one).
 */
class Status implements NotificationTypeInterface
{
    public const CODE = 'app_health_status';
    /** Log errors listed, the most frequent first. */
    public const ERRORS = 5;

    public function __construct(
        private readonly TeamMessage $teamMessage,
        private readonly StateStore $store,
        private readonly Messages $messages,
        private readonly CheckPool $pool,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    public function process(string $reference): void
    {
        $this->teamMessage->deliver(self::CODE, '0', function (Target $target, array $row): ?array {
            if (!$this->config->isHourlyStatusEnabled()) {
                return null;
            }
            $now = $this->dateTime->gmtTimestamp();
            $sentAt = (string)($row['sent_at'] ?? '');
            $from = $sentAt !== '' ? (int)strtotime($sentAt . ' UTC')
                : $now - $this->config->getHourlyStatusMinutes() * 60;
            $rows = $this->store->load(['checks', Fingerprints::KEY, Fingerprints::ERRORS_KEY]);
            $problems = [];
            $warnings = [];
            foreach ((array)($rows['checks'] ?? []) as $checks) {
                foreach ((array)$checks as $code => $check) {
                    $status = $check['status'] ?? CheckResult::OK;
                    if ($status === CheckResult::FAIL) {
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
            return [$this->messages->status($target, array_values($problems), array_values($warnings),
                array_sum(array_column($byFingerprint, 'count')), $errors, count($byFingerprint) - count($errors)),
                fn () => null];
        });
    }
}
