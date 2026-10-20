<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Status;

use CommerceKings\AppHealth\Api\Data\StatusReport;
use CommerceKings\AppHealth\Api\StatusPolicyInterface;
use CommerceKings\AppHealth\Model\Config;

/**
 * The shipped policy: the interval is *Hourly status every* (60 minutes); a status with news is posted - a problem or
 * warning the last posted status did not have, new errors - and "All checks OK" once a day (the first status at or
 * after *Daily summary at*); every other one is delivered quietly: what a posted status told is in the daily summary.
 */
class DefaultPolicy implements StatusPolicyInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getIntervalMinutes(int $scopeId): int
    {
        return $this->config->getHourlyStatusMinutes();
    }

    public function shouldPost(StatusReport $report): bool
    {
        if ($report->hasNews()) {
            return true; // only what the last posted status did not say: what it said is in the daily summary
        }
        if (!$report->isAllOk()) {
            return false;
        }
        $minute = $this->config->getSummaryMinute();
        $slot = $report->now->setTime(intdiv($minute, 60), $minute % 60);
        if ($slot > $report->now) {
            $slot = $slot->modify('-1 day'); // before today's time: yesterday's is the latest
        }
        return $report->lastOkPostedAt === null || $report->lastOkPostedAt < $slot->getTimestamp();
    }
}
