<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\StateStore;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Notification type "app_health_alert", reference = the scope: 0 for the shop-wide checks (to the default scope's
 * recipient), else a website id for its own checks. What changed since that team was last told, on the checks'
 * latest results - a problem, a recovery, a fail repeated after the repeat interval. Nothing new (e.g. recovered
 * again before it went out): nothing is sent.
 */
class Alert implements NotificationTypeInterface
{
    public const CODE = 'app_health_alert';

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
        $this->teamMessage->deliver(self::CODE, $reference, function (Target $target): ?array {
            $now = $this->dateTime->gmtTimestamp();
            $key = 'alerted:' . $target->websiteId;
            $rows = $this->store->load(['checks', $key]);
            $checks = (array)($rows['checks'][$target->websiteId] ?? []);
            $alerted = $rows[$key] ?? [];
            $changes = AlertPlan::changes($checks, $alerted, $now, $this->config->getRepeatHours() * 3600);
            if ($changes === []) {
                return null;
            }
            return [$this->messages->alert($target, $checks, $changes),
                fn () => $this->store->save([$key => AlertPlan::told($checks, $alerted, $changes, $now)])];
        });
    }
}
