<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\StateStore;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Notification type "app_health_alert", reference = the scope: 0 for the shop-wide checks (to the default scope's
 * recipient), else a website id for its own checks. One message per piece of news, on the checks' latest results:
 * fails first, then warnings, then recoveries, then each check's new notices - each sent and recorded on its own
 * (at most MAX_MESSAGES per message from the queue; the next run queues the rest). Nothing new (e.g. recovered
 * again before it went out): nothing is sent.
 */
class Alert implements NotificationTypeInterface
{
    public const CODE = 'app_health_alert';
    public const MAX_MESSAGES = 10;

    public function __construct(
        private readonly TeamMessage $teamMessage,
        private readonly StateStore $store,
        private readonly Messages $messages,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    public function process(string $reference): void
    {
        for ($i = 0; $i < self::MAX_MESSAGES; $i++) {
            if (!$this->teamMessage->deliver(self::CODE, $reference, fn (Target $target) => $this->next($target))) {
                return;
            }
        }
    }

    /** @return array{0: string, 1: \Closure(): void}|null the most important news not told yet */
    private function next(Target $target): ?array
    {
        $now = $this->dateTime->gmtTimestamp();
        $scope = $target->websiteId;
        $keys = ['alerted:' . $scope, AlertPlan::NOTICES . $scope, AlertPlan::NOTICED . $scope];
        $rows = $this->store->load(array_merge(['checks'], $keys));
        $checks = AlertPlan::scopeChecks((array)($rows['checks'] ?? []), $scope,
            $this->config->isSharedToWebsites() && $target->ownChannel);
        $alerted = $rows[$keys[0]] ?? [];
        $changes = AlertPlan::changes($checks, $alerted, $now, $this->config->getRepeatHours() * 3600,
            $this->config->getRecoverRuns());
        uksort($changes, fn ($a, $b) => $this->order($checks[$b] ?? [], $changes[$b])
            <=> $this->order($checks[$a] ?? [], $changes[$a]));
        foreach ($changes as $code => $change) {
            return [$this->messages->alert($target, (string)$code, $checks[$code] ?? [], $change),
                fn () => $this->store->save([$keys[0] => AlertPlan::told($checks, $alerted,
                    [$code => $change], $now)])];
        }
        $noticed = $rows[$keys[2]] ?? [];
        $gap = $this->config->getNoticeMinutes() * 60;
        foreach (AlertPlan::pendingNotices($rows[$keys[1]] ?? [], $noticed, $now, $gap) as $code => $notices) {
            return [$this->messages->notices($target, $code, $notices), fn () => $this->store->save([$keys[2]
                => AlertPlan::noticed($noticed, (string)$code, max(array_keys($notices)), $now)])];
        }
        return null;
    }

    /** Fails first, then warnings, then recoveries. */
    private function order(array $check, string $change): int
    {
        return $change === AlertPlan::RECOVERED ? 0 : CheckResult::rank((string)($check['status'] ?? 'ok'));
    }
}
