<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

/**
 * Where a message goes: the global one's (scope 0, sent from the default store view; the shop's name and time zone)
 * or one website's (its *Website channels* row, else the global channel - ownChannel false; sent from its default
 * store view; its name and time zone).
 */
class Target
{
    public function __construct(
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly string $name,
        public readonly string $timezone,
        public readonly bool $ownChannel = true
    ) {
    }

    public function isShopWide(): bool
    {
        return $this->websiteId === 0;
    }
}
