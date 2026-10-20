<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\System;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\SiteDomain;
use Magento\Framework\Notification\MessageInterface;

/**
 * The admin's system message while App health is on but silent because this looks like a copy of another shop (its
 * stored domain is not this shop's): where to accept the new domain if the shop has moved.
 */
class CopyMessage implements MessageInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly SiteDomain $siteDomain
    ) {
    }

    public function getIdentity(): string
    {
        return 'commercekings_app_health_copy_' . sha1($this->siteDomain->stored() . '|' . $this->siteDomain->current());
    }

    public function isDisplayed(): bool
    {
        return $this->config->isEnabled() && !$this->siteDomain->matches();
    }

    public function getText(): string
    {
        return (string)__('App health is silent: this looks like a copy of %1 (this shop is %2), so it sends no alerts'
            . ' and pings no heartbeat. If the shop has moved, use the new domain in Stores > Configuration >'
            . ' CommerceKings > App health.', $this->siteDomain->stored(), $this->siteDomain->current());
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }
}
