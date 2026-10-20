<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Cron;

use CommerceKings\AppHealth\Model\Notification\Summary;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\SiteDomain;
use CommerceKings\AppHealth\Model\SummarySchedule;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Cron "commercekings_app_health_summary", every 15 minutes: queues the global daily summary (scope 0) and that of
 * each website with a channel of its own, once the summary time has passed in its time zone, unless it was sent since
 * (the condition is repeated in the queue mark's statement). Outside the window no query at all; inside it, one read
 * per summary once it is out.
 */
class QueueSummaries
{
    public function __construct(
        private readonly Targets $targets,
        private readonly Config $config,
        private readonly SummarySchedule $schedule,
        private readonly TeamMessage $teamMessage,
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly DeliveryStateInterface $deliveryState,
        private readonly ?SiteDomain $siteDomain = null
    ) {
    }

    /** @return int[] the websites queued */
    public function execute(): array
    {
        $now = $this->dateTime->gmtTimestamp();
        $queued = [];
        $shopWide = $this->targets->shopWide();
        if ($shopWide === null || !$this->config->isSummaryEnabled()
            || ($this->siteDomain !== null && !$this->siteDomain->matches())) { // a copy: silent
            return [];
        }
        // the global summary, and one per website with a channel of its own (the others are in the global one)
        $targets = [0 => $shopWide]
            + array_filter($this->targets->websites(), fn (Target $target) => $target->ownChannel);
        foreach ($targets as $websiteId => $target) {
            $slot = $this->schedule->latestSlot($target, $now);
            if (!$this->schedule->isDue($slot, null, $now)) {
                continue;
            }
            // one read while the day's summary is out (or on its way): nothing more to do
            $row = $this->deliveryState->load(Summary::CODE, $websiteId);
            if ($row !== null && ($row['queued_at'] !== null
                || !$this->schedule->isDue($slot, $row['sent_at'] ?? null, $now))) {
                continue;
            }
            $notSince = $this->resource->getConnection()->quoteInto('(sent_at IS NULL OR sent_at < ?)',
                gmdate('Y-m-d H:i:s', $slot));
            try {
                if ($this->teamMessage->queue(Summary::CODE, $websiteId, $notSince)) {
                    $queued[] = $websiteId;
                }
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('CommerceKings_AppHealth: summary for website %d not queued (%s)',
                    $websiteId, get_class($e)));
            }
        }
        return $queued;
    }
}
