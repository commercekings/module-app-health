<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\Log\LogReader;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\Sensitive\Detector;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Sensitive data written into the logs (the system log and exception sources, every level): a card number, a
 * secret, personal data in bulk - by the rule sets in force (Sensitive\Rules). A log holding it is a security problem
 * of its own, so each finding is a notice at once (🚨 critical, 🟡 warning) - the rule's category and label, how often,
 * the last time, where and the fingerprint, never the value - and again after the repeat interval while it goes on.
 * The findings are kept per rule and fingerprint in the "sensitive" row (at most LIMIT). Counts: found, by rule.
 */
class SensitiveData implements CheckInterface
{
    public const CODE = 'sensitive_data';
    public const KEY = 'sensitive';
    public const LIMIT = 200;
    public const NOTICES = 5;

    public function __construct(
        private readonly LogReader $reader,
        private readonly Detector $detector,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime
    ) {
    }

        /** What to do, for a problem without a hint of its own. */
    private function hint(): string
    {
        return (string)__('Stop logging it where the entry is written, then remove or rotate the log file.');
    }

    public function getLabel(): string
    {
        return 'Sensitive data in logs';
    }

    public function run(array $websiteIds): array
    {
        return [self::SHOP => $this->check()->withHint($this->hint())];
    }

    private function check(): CheckResult
    {
        $now = $this->dateTime->gmtTimestamp();
        $findings = [];
        foreach ([SystemLog::CODE, Exceptions::CODE] as $check) {
            foreach ($this->reader->entries($check)['sensitive'] as $entry) {
                foreach ((array)$entry['sensitive'] as $rule => $matches) {
                    $key = $rule . '|' . $entry['fingerprint'];
                    $findings[$key] ??= ['rule' => (string)$rule, 'fp' => (string)$entry['fingerprint'], 'count' => 0,
                        'last' => 0, 'location' => $entry['location'], 'source' => (string)$entry['source']];
                    $findings[$key]['count'] += (int)$matches;
                    $findings[$key]['last'] = max($findings[$key]['last'], (int)($entry['time'] ?? $now));
                }
            }
        }
        if ($findings === []) {
            return CheckResult::ok('', ['found' => 0]);
        }
        $rules = $this->detector->rules();
        $known = (array)($this->state->get(self::KEY) ?? []);
        $repeat = $this->config->getRepeatHours() * 3600;
        $counts = ['found' => 0];
        $news = [];
        foreach ($findings as $key => $finding) {
            $seen = $known[$key] ?? null;
            $tell = $seen === null || (int)($seen['told'] ?? 0) <= $now - $repeat;
            $known[$key] = ['first' => (int)($seen['first'] ?? $now), 'last' => $finding['last'],
                'count' => (int)($seen['count'] ?? 0) + $finding['count'], 'told' => $tell ? $now : (int)$seen['told']];
            $counts['found'] += $finding['count'];
            $item = 'found:' . str_replace('/', '.', $finding['rule']);
            $counts[$item] = ($counts[$item] ?? 0) + $finding['count'];
            if ($tell && isset($rules[$finding['rule']])) {
                $news[] = $finding + ['severity' => $rules[$finding['rule']]['severity']];
            }
        }
        if (count($known) > self::LIMIT) {
            uasort($known, fn ($a, $b) => (int)$b['last'] <=> (int)$a['last']);
            $known = array_slice($known, 0, self::LIMIT, true);
        }
        $this->state->set(self::KEY, $known);
        usort($news, fn ($a, $b) => [$b['severity'] === 'critical', $b['count']] <=> [$a['severity'] === 'critical',
            $a['count']]);
        return new CheckResult(CheckResult::OK, '', $counts, $this->notices($news, $rules));
    }

    /**
     * "PCI: payment card number in system.log (3×, last {t})" and "Where: Payment/Model/Gateway.php:120" per finding,
     * the most serious first, then each category's hint once ("Check: ...").
     *
     * @param list<array<string, mixed>> $news
     * @param array<string, array<string, mixed>> $rules
     * @return string[]
     */
    private function notices(array $news, array $rules): array
    {
        $lines = [];
        $hints = [];
        foreach (array_slice($news, 0, self::NOTICES) as $finding) {
            $rule = $rules[$finding['rule']];
            $lines[] = $rule['setLabel'] . ': ' . $rule['label'] . ' in ' . $finding['source'] . ' ('
                . Format::number($finding['count']) . '×, last ' . Format::at($finding['last']) . ')'
                . ($finding['location'] ? "\nWhere: " . $finding['location'] : '');
            if ($rule['hint'] !== '') {
                $hints[$rule['hint']] = true;
            }
        }
        if (count($news) > self::NOTICES) {
            $lines[] = '…and ' . (count($news) - self::NOTICES) . ' more';
        }
        foreach (array_keys($hints) as $hint) {
            $lines[] = 'Check: ' . $hint;
        }
        return $lines;
    }
}
