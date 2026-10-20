<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\StateStore;
use CommerceKings\AppHealth\Model\SummarySchedule;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Notification type "app_health_summary", reference = website id: once a day at the website's summary time, to the
 * website's recipient - the shop-wide checks and the website's own: those not ok now, the counts of the last 24
 * hours, those that had problems and those ok all day.
 */
class Summary implements NotificationTypeInterface
{
    public const CODE = 'app_health_summary';

    public function __construct(
        private readonly TeamMessage $teamMessage,
        private readonly StateStore $store,
        private readonly Messages $messages,
        private readonly Config $config,
        private readonly SummarySchedule $schedule,
        private readonly DateTime $dateTime
    ) {
    }

    public function process(string $reference): void
    {
        $this->teamMessage->deliver(self::CODE, $reference, function (Target $target, array $row): ?array {
            $now = $this->dateTime->gmtTimestamp();
            if (!$this->config->isSummaryEnabled($target->websiteId)
                || !$this->schedule->isDue($this->schedule->latestSlot($target, $now), $row['sent_at'] ?? null, $now)) {
                return null;
            }
            $scopes = [0, $target->websiteId];
            $from = 'tally:' . gmdate('YmdH', $now - 23 * 3600);
            $tallies = [];
            foreach ($this->store->loadPrefix('tally:') as $key => $tally) {
                if ($key >= $from) {
                    $tallies[] = array_replace(...array_map(fn ($scope) => (array)($tally[$scope] ?? []), $scopes));
                }
            }
            $all = $this->store->load(['checks'])['checks'] ?? [];
            $checks = array_replace(...array_map(fn ($scope) => (array)($all[$scope] ?? []), $scopes));
            return [$this->messages->summary($target, $checks, $tallies, $now), fn () => null];
        });
    }
}
