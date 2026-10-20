<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * Who gets which message - every setting is global (the default scope). The global channel and recipient (scope 0)
 * get the shared checks (cron, logs, queues, ...), security findings, the hourly status and the global daily summary;
 * a website's own messages (its email settings, its unsent sales emails, its daily summary; scope = its id) go to its
 * *Website channels* row, else to the global channel (its lines then name the website). Nothing while app health is
 * off or without a global channel and recipient.
 */
class Targets
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /** The global target (scope 0), or null while app health is off. */
    public function shopWide(): ?Target
    {
        return $this->config->isEnabled() ? $this->global() : null;
    }

    /** @return array<int, Target> every website's target, by id - while app health is on */
    public function websites(): array
    {
        if ($this->shopWide() === null) {
            return [];
        }
        $own = $this->config->getWebsiteChannels();
        $targets = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $channel = $own[$websiteId] ?? ['channel' => $this->config->getChannel(),
                'recipient' => $this->config->getRecipient()];
            $target = $websiteId > 0 ? $this->target($websiteId, $channel['channel'], $channel['recipient'],
                isset($own[$websiteId])) : null;
            if ($target !== null) {
                $targets[$websiteId] = $target;
            }
        }
        ksort($targets);
        return $targets;
    }

    /** Scope 0: the global target; else the website's - while app health is on. */
    public function forWebsite(int $websiteId): ?Target
    {
        return $websiteId === 0 ? $this->shopWide() : ($this->websites()[$websiteId] ?? null);
    }

    /** The global channel and recipient whether app health is on or not (the test alert); null without them. */
    public function global(): ?Target
    {
        return $this->target(0, $this->config->getChannel(), $this->config->getRecipient(), true);
    }

    private function target(int $websiteId, string $channel, string $recipient, bool $ownChannel): ?Target
    {
        if ($channel === '' || $recipient === '') {
            return null;
        }
        try {
            $website = $websiteId === 0 ? null : $this->storeManager->getWebsite($websiteId);
            $store = $website === null ? $this->storeManager->getDefaultStoreView()
                : ($website instanceof Website ? $website->getDefaultStore() : null);
        } catch (LocalizedException) {
            return null;
        }
        if ($store === null || !$store->getId()) {
            return null;
        }
        $storeId = (int)$store->getId();
        return new Target($websiteId, $storeId, $channel, $recipient, $this->storeName($store),
            (string)$this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string)$storeId), $ownChannel);
    }

    /**
     * The shop's name as the other CommerceKings messages give it: the store's Store Information name, else the
     * store's frontend name (its store group's name) - never the store view's name.
     */
    private function storeName(\Magento\Store\Api\Data\StoreInterface $store): string
    {
        $name = trim((string)$this->scopeConfig->getValue('general/store_information/name',
            ScopeInterface::SCOPE_STORE, (int)$store->getId()));
        if ($name !== '') {
            return $name;
        }
        return (string)($store instanceof Store ? $store->getFrontendName() : $store->getName());
    }
}
