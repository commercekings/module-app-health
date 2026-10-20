<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "count" or more new exception.log entries within "window_minutes" (fail), with the top 3 exception classes, and a
 * notice for an exception never seen before ("NEW CRITICAL", with where it happened and its fingerprint). The check
 * keeps each run's count and classes for the window. Counts: exceptions, by class, and by fingerprint.
 */
class Exceptions implements CheckInterface
{
    public const CODE = 'exceptions';
    /** Classes kept per run and per count key. */
    private const CLASSES = 10;

    public function __construct(
        private readonly ExceptionLog $log,
        private readonly Fingerprints $fingerprints,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime
    ) {
    }

        /** What to do, for a problem without a hint of its own. */
    private function hint(): string
    {
        return (string)__('var/log/exception.log: look for the class around that time, in the file it names.');
    }

    public function getLabel(): string
    {
        return 'exception.log';
    }

    public function run(array $websiteIds): array
    {
        return [self::SHOP => $this->check()->withHint($this->hint())];
    }

    private function check(): CheckResult
    {
        $now = $this->dateTime->gmtTimestamp();
        $new = $this->log->newEntries();
        $classes = $new['classes'];
        arsort($classes);
        $classes = array_slice($classes, 0, self::CLASSES, true);
        $window = $this->config->getInt(self::CODE, 'window_minutes', 15) * 60;
        $samples = array_values(array_filter((array)($this->state->get('cursor:' . self::CODE)['samples'] ?? []),
            fn ($sample) => is_array($sample) && (int)($sample[0] ?? 0) > $now - $window));
        if ($new['entries'] > 0) {
            $samples[] = [$now, $new['entries'], $classes, $new['partial']];
        }
        $this->state->set('cursor:' . self::CODE, ['samples' => $samples]);
        $counts = ['exceptions' => $new['entries']];
        foreach ($classes as $class => $count) {
            $counts['exceptions:' . $class] = $count;
        }
        $byFingerprint = array_count_values(array_column($new['safe'], 'fingerprint'));
        arsort($byFingerprint);
        foreach (array_slice($byFingerprint, 0, self::CLASSES, true) as $fp => $count) {
            $counts['fp:' . $fp] = $count;
        }
        // every exception is CRITICAL: only an exception never seen before is news
        $news = $this->fingerprints->record($new['safe'], $now, false);
        $notices = array_map([Fingerprints::class, 'notice'], array_slice($news, 0, SystemLog::NOTICES));
        if (count($news) > SystemLog::NOTICES) {
            $notices[] = sprintf('…and %d more (see the daily summary)', count($news) - SystemLog::NOTICES);
        }
        $total = 0;
        $partial = false;
        $byClass = [];
        foreach ($samples as $sample) {
            $total += (int)$sample[1];
            $partial = $partial || !empty($sample[3]);
            foreach ((array)$sample[2] as $class => $count) {
                $class = substr((string)strrchr('\\' . $class, '\\'), 1); // without its namespace
                $byClass[$class] = ($byClass[$class] ?? 0) + (int)$count;
            }
        }
        $gauges = $new['skipped'] > 0 ? ['skipped kb' => intdiv($new['skipped'], 1024)] : [];
        if ($total < $this->config->getInt(self::CODE, 'count', 20)) {
            return new CheckResult(CheckResult::OK, '', $counts, $notices, '', $gauges);
        }
        return new CheckResult(CheckResult::FAIL, sprintf('%s%s exceptions in %s (%s)%s', $partial ? 'at least ' : '',
            Format::number($total), Format::duration($window), Format::top($byClass), $new['skipped'] > 0
                ? '; ' . Format::bytes($new['skipped']) . ' of the log not read' : ''), $counts, $notices,
            'Exception spike', $gauges);
    }
}
