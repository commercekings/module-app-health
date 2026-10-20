<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\System;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Runner;
use CommerceKings\AppHealth\Model\SiteDomain;
use CommerceKings\AppHealth\Model\StateStore;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The admin's system message while the hourly status - the proof that alerts can go out - has been due for more than
 * GRACE_MINUTES and is still not delivered: most likely Notification's queue consumer is not running. Read from the
 * "status" state row the runner keeps (due_since); one query.
 */
class StatusOverdueMessage implements MessageInterface
{
    /** Past the runner's own grace (the heartbeat stops at Runner::STATUS_GRACE_MINUTES), so it is not a slow run. */
    public const GRACE_MINUTES = Runner::STATUS_GRACE_MINUTES + 5;

    private ?int $overdue = null;

    public function __construct(
        private readonly Config $config,
        private readonly StateStore $store,
        private readonly SiteDomain $siteDomain,
        private readonly DateTime $dateTime
    ) {
    }

    public function getIdentity(): string
    {
        return 'commercekings_app_health_status_overdue';
    }

    public function isDisplayed(): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isHourlyStatusEnabled() || !$this->siteDomain->matches()) {
            return false;
        }
        $dueSince = (int)($this->store->load(['status'])['status']['due_since'] ?? 0);
        $minutes = $dueSince > 0 ? intdiv((int)$this->dateTime->gmtTimestamp() - $dueSince, 60) : 0;
        $this->overdue = $minutes >= self::GRACE_MINUTES ? $minutes : null;
        return $this->overdue !== null;
    }

    public function getText(): string
    {
        return (string)__('App health\'s hourly status is %1 min overdue, so alerts may not be reaching you. Is the'
            . ' queue consumer commerceKingsNotificationSend running? (Magento\'s cron starts it with'
            . ' cron_consumers_runner in app/etc/env.php, else bin/magento queue:consumers:start'
            . ' commerceKingsNotificationSend.)', (string)$this->overdue);
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
