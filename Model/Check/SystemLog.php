<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Log\LogReader;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Magento's merged log (var/log/system.log by default, the admin's extra var/log files, and any other module's log
 * source for "system_log"): new entries by level, notices for an error never seen before ("NEW ERROR", with where it
 * happened and its fingerprint) and for CRITICAL and above (again after the repeat interval), and "error_count" or
 * more errors (ERROR and above) within "window_minutes" (fail, the top 3 by fingerprint). Counts: critical, errors,
 * warnings and each entry's fingerprint (the summary lists them).
 */
class SystemLog implements CheckInterface
{
    public const CODE = 'system_log';
    /** Notices per run; the rest are counted in one more line. */
    public const NOTICES = 5;
    /** Fingerprint counts kept per run: errors, warnings. */
    private const FINGERPRINTS = 20;
    private const WARNINGS = 10;

    public function __construct(
        private readonly LogReader $reader,
        private readonly Fingerprints $fingerprints,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getHint(): string
    {
        return (string)__('var/log/system.log: look for the fingerprint\'s error around that time.');
    }

    public function getLabel(): string
    {
        return 'system.log';
    }

    public function run(): CheckResult
    {
        $now = $this->dateTime->gmtTimestamp();
        $read = $this->reader->entries(self::CODE);
        $levels = ['critical' => 0, 'errors' => 0, 'warnings' => 0];
        $byFingerprint = [];
        $warnings = [];
        $labels = [];
        foreach ($read['entries'] as $entry) {
            $levels[[1 => 'warnings', 2 => 'errors', 3 => 'critical'][(int)$entry['rank']]]++;
            $fp = (string)$entry['fingerprint'];
            if ((int)$entry['rank'] >= 2) {
                $byFingerprint[$fp] = ($byFingerprint[$fp] ?? 0) + 1;
                $labels[$fp] = $entry['label'];
            } else {
                $warnings[$fp] = ($warnings[$fp] ?? 0) + 1;
            }
        }
        arsort($byFingerprint);
        arsort($warnings);
        $byFingerprint = array_slice($byFingerprint, 0, self::FINGERPRINTS, true);
        $counts = $levels;
        foreach ($byFingerprint + array_slice($warnings, 0, self::WARNINGS, true) as $fp => $count) {
            $counts['fp:' . $fp] = $count;
        }
        $notices = $this->notices($this->fingerprints->record($read['entries'], $now, true));

        $window = $this->config->getInt(self::CODE, 'window_minutes', 5) * 60;
        $samples = array_values(array_filter((array)($this->state->get('cursor:' . self::CODE)['samples'] ?? []),
            fn ($sample) => is_array($sample) && (int)($sample[0] ?? 0) > $now - $window));
        $errors = $levels['errors'] + $levels['critical'];
        if ($errors > 0) {
            $top = [];
            foreach (array_slice($byFingerprint, 0, 3, true) as $fp => $count) {
                $top[$labels[$fp] . ' #' . $fp] = $count;
            }
            $samples[] = [$now, $errors, $top, $read['partial']];
        }
        if ($samples !== [] || $this->state->get('cursor:' . self::CODE) !== null) {
            $this->state->set('cursor:' . self::CODE, ['samples' => $samples]);
        }
        $total = 0;
        $partial = false;
        $top = [];
        foreach ($samples as $sample) {
            $total += (int)$sample[1];
            $partial = $partial || !empty($sample[3]);
            foreach ((array)$sample[2] as $label => $count) {
                $top[$label] = ($top[$label] ?? 0) + (int)$count;
            }
        }
        if ($total < $this->config->getInt(self::CODE, 'error_count', 20)) {
            return new CheckResult(CheckResult::OK, '', $counts, $notices);
        }
        return new CheckResult(CheckResult::FAIL, sprintf('%s%s errors in %s (%s)', $partial ? 'at least ' : '',
            Format::number($total), Format::duration($window), Format::top($top)), $counts, $notices, 'Error spike');
    }

    /**
     * @param list<array<string, mixed>> $news Fingerprints::record()
     * @return string[]
     */
    private function notices(array $news): array
    {
        $notices = array_map([Fingerprints::class, 'notice'], array_slice($news, 0, self::NOTICES));
        $more = count($news) - self::NOTICES;
        if ($more > 0) {
            $notices[] = sprintf('+%d more new or critical errors (see the daily summary)', $more);
        }
        return $notices;
    }
}
