<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Cron;

use CommerceKings\AppHealth\Model\Notification\Summary;
use CommerceKings\AppHealth\Model\Notification\TeamMessage;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\SummarySchedule;
use CommerceKings\AppHealth\Model\Targets;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Cron "commercekings_app_health_summary", every 15 minutes: queues each website's daily summary once its summary
 * time has passed, unless it was sent since (the condition is repeated in the queue mark's statement). Outside the
 * summary's window no query at all; inside it, one read per website once the summary is out.
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
        private readonly DeliveryStateInterface $deliveryState
    ) {
    }

    /** @return int[] the websites queued */
    public function execute(): array
    {
        $now = $this->dateTime->gmtTimestamp();
        $queued = [];
        foreach ($this->targets->websites() as $websiteId => $target) {
            if (!$this->config->isSummaryEnabled($websiteId)) {
                continue;
            }
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
