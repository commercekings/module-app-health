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
 * Who gets which alert: the shop-wide checks go once to the default scope's channel and recipient (website 0); each
 * website's own checks and its daily summary go to the website's (inherited from the default unless it sets its
 * own). Only where app health is on and a channel and recipient are set.
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

    /** The shop-wide checks' target, or null while they are off. */
    public function shopWide(): ?Target
    {
        return $this->config->isEnabled(0) ? $this->resolve(0) : null;
    }

    /** @return array<int, Target> the websites with alerts on, by id */
    public function websites(): array
    {
        $targets = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $target = $websiteId > 0 && $this->config->isEnabled($websiteId) ? $this->resolve($websiteId) : null;
            if ($target !== null) {
                $targets[$websiteId] = $target;
            }
        }
        ksort($targets);
        return $targets;
    }

    /** Website 0: the shop-wide target; else the website's - while alerts are on there. */
    public function forWebsite(int $websiteId): ?Target
    {
        return $websiteId === 0 ? $this->shopWide() : ($this->websites()[$websiteId] ?? null);
    }

    /** The scope's channel and recipient whether alerts are on or not (the test alert); null without them. */
    public function resolve(int $websiteId): ?Target
    {
        $channel = $this->config->getChannel($websiteId);
        $recipient = $this->config->getRecipient($websiteId);
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
            (string)$this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string)$storeId));
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
