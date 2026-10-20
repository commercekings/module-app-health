<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Status;

use CommerceKings\AppHealth\Api\Data\StatusReport;
use CommerceKings\AppHealth\Api\StatusPolicyInterface;
use CommerceKings\AppHealth\Model\Config;

/**
 * The shipped policy: the interval is *Hourly status every* (60 minutes); a status with problems, warnings or new
 * errors is posted, "All checks OK" once a day - the first status at or after the scope's *Daily summary at* time -
 * and every other one is delivered quietly.
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
        if (!$report->isAllOk()) {
            return true;
        }
        $minute = $this->config->getSummaryMinute();
        $slot = $report->now->setTime(intdiv($minute, 60), $minute % 60);
        if ($slot > $report->now) {
            $slot = $slot->modify('-1 day'); // before today's time: yesterday's is the latest
        }
        return $report->lastOkPostedAt === null || $report->lastOkPostedAt < $slot->getTimestamp();
    }
}
