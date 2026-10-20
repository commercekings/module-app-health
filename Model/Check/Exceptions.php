<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "count" or more new exception.log entries within "window_minutes" (fail), with the top 3 exception classes. The
 * check keeps each run's count and classes for the window. Counts: exceptions, by class.
 */
class Exceptions implements CheckInterface
{
    public const CODE = 'exceptions';
    /** Classes kept per run and per count key. */
    private const CLASSES = 10;

    public function __construct(
        private readonly ExceptionLog $log,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Exceptions';
    }

    public function run(): CheckResult
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
        $total = 0;
        $partial = false;
        $byClass = [];
        foreach ($samples as $sample) {
            $total += (int)$sample[1];
            $partial = $partial || !empty($sample[3]);
            foreach ((array)$sample[2] as $class => $count) {
                $byClass[$class] = ($byClass[$class] ?? 0) + (int)$count;
            }
        }
        if ($total < $this->config->getInt(self::CODE, 'count', 20)) {
            return CheckResult::ok('', $counts);
        }
        return CheckResult::fail(sprintf('%s%s exceptions in %s (%s)', $partial ? 'at least ' : '',
            Format::number($total), Format::duration($window), Format::top($byClass)), $counts);
    }
}
