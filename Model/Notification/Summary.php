<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Notification;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Check\SensitiveData;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Sensitive\Detector;
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
        private readonly Targets $targets,
        private readonly Detector $detector
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
            $rows = $this->store->load(['checks', Fingerprints::KEY, SensitiveData::KEY]);
            $checks = $this->named((array)($rows['checks'] ?? []), $scopes);
            $security = $target->isShopWide()
                ? $this->security($tallies, (array)($rows[SensitiveData::KEY] ?? []), $now) : [];
            return [$this->messages->summary($target, $checks, $tallies, $now, $rows[Fingerprints::KEY] ?? [],
                $security),
                fn () => null];
        });
    }

    /**
     * The day's sensitive-data findings, a line per rule (the latest first): "PCI: payment card number in system.log
     * (3×, {t:...})" - the day's count from the tallies, where and when from the "sensitive" row; never a value.
     *
     * @param list<array<string, mixed>> $tallies the day's hours, named
     * @param array<string, array<mixed>> $known the "sensitive" row: "<set>/<rule>|<fingerprint>" => last, source, ...
     * @return string[]
     */
    private function security(array $tallies, array $known, int $now): array
    {
        $counts = [];
        foreach ($tallies as $hour) {
            foreach ((array)($hour[SensitiveData::CODE]['c'] ?? []) as $key => $count) {
                if (str_starts_with((string)$key, 'found:')) {
                    $rule = str_replace('.', '/', substr((string)$key, 6));
                    $counts[$rule] = ($counts[$rule] ?? 0) + (int)$count;
                }
            }
        }
        $rules = $this->detector->rules();
        $latest = [];
        foreach ($known as $key => $finding) {
            $rule = (string)strstr((string)$key, '|', true);
            if (($counts[$rule] ?? 0) > 0 && isset($rules[$rule]) && (int)($finding['last'] ?? 0) > $now - 86400
                && (int)$finding['last'] > (int)($latest[$rule]['last'] ?? 0)) {
                $latest[$rule] = $finding;
            }
        }
        uasort($latest, fn ($a, $b) => (int)$b['last'] <=> (int)$a['last']);
        $lines = [];
        foreach ($latest as $rule => $finding) {
            $lines[] = $rules[$rule]['setLabel'] . ': ' . $rules[$rule]['label']
                . (!empty($finding['source']) ? ' in ' . $finding['source'] : '') . ' ('
                . Format::number($counts[$rule]) . '×, ' . Format::at((int)$finding['last']) . ')';
        }
        return $lines;
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
