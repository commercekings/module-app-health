<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

/**
 * Where alerts go: the shop-wide checks' (website 0, the default scope's settings, sent from the default store view)
 * or one website's (its settings, sent from its default store view).
 */
class Target
{
    public function __construct(
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly string $name,
        public readonly string $timezone
    ) {
    }

    public function isShopWide(): bool
    {
        return $this->websiteId === 0;
    }
}
