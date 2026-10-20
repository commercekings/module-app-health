<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\StateStore;
use CommerceKings\AppHealth\Model\SummarySchedule;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Notification type "app_health_summary", once a day at the summary time: reference 0, the global summary to the
 * global recipient - the shared checks, and the own checks of each website without a channel of its own (named:
 * "Email sending (EU Store)"); reference = a website id, that website's summary to its own channel - its own checks
 * only. Each: those not ok now, the counts of the last 24 hours, those that had problems and those ok all day.
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
        private readonly DateTime $dateTime,
        private readonly Targets $targets
    ) {
    }

    public function process(string $reference): void
    {
        $this->teamMessage->deliver(self::CODE, $reference, function (Target $target, array $row): ?array {
            $now = $this->dateTime->gmtTimestamp();
            if (!$this->config->isSummaryEnabled()
                || !$this->schedule->isDue($this->schedule->latestSlot($target, $now), $row['sent_at'] ?? null, $now)) {
                return null;
            }
            // scope => the suffix of its check codes ("" for its own, "@<website>" for a named website's)
            $scopes = $target->isShopWide() ? [0 => ''] + array_map(fn (Target $website) => '@' . $website->name,
                array_filter($this->targets->websites(), fn (Target $website) => !$website->ownChannel))
                : [$target->websiteId => ''];
            $from = 'tally:' . gmdate('YmdH', $now - 23 * 3600);
            $tallies = [];
            foreach ($this->store->loadPrefix('tally:') as $key => $tally) {
                if ($key >= $from) {
                    $tallies[] = $this->named($tally, $scopes);
                }
            }
            $rows = $this->store->load(['checks', Fingerprints::KEY]);
            $checks = $this->named((array)($rows['checks'] ?? []), $scopes);
            return [$this->messages->summary($target, $checks, $tallies, $now, $rows[Fingerprints::KEY] ?? []),
                fn () => null];
        });
    }

    /**
     * The scopes' entries in one map, a website's codes named for it.
     *
     * @param array<int|string, mixed> $byScope scope => code => value
     * @param array<int, string> $scopes scope => suffix
     * @return array<string, array<mixed>>
     */
    private function named(array $byScope, array $scopes): array
    {
        $named = [];
        foreach ($scopes as $scope => $suffix) {
            foreach ((array)($byScope[$scope] ?? []) as $code => $value) {
                $named[$code . $suffix] = (array)$value;
            }
        }
        return $named;
    }
}
