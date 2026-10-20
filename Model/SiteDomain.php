<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The shop's domain, so a copy (staging, a developer's database) stays silent: the domain stored when App health was
 * first saved (commercekings_app_health/general/domain) against the one the shop has now - the default store view's
 * secure base URL. A copy has the original's stored domain and its own base URL: it does not match, and the runtime
 * sends nothing and pings nothing until an admin accepts the new domain (accept()).
 */
class SiteDomain
{
    public const PATH = 'commercekings_app_health/general/domain';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    /** The host of the default store view's secure base URL, lower case, e.g. "shop.example.com"; '' when unknown. */
    public function current(): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();
            $url = $store instanceof Store ? $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true) : '';
        } catch (\Exception) {
            return '';
        }
        return strtolower((string)parse_url($url, PHP_URL_HOST));
    }

    /** The domain stored when App health was first saved, or accepted since; '' when never. */
    public function stored(): string
    {
        return strtolower(trim((string)$this->scopeConfig->getValue(self::PATH)));
    }

    /** Whether this is the shop App health was set up on: nothing stored yet, or the same domain. */
    public function matches(): bool
    {
        $stored = $this->stored();
        return $stored === '' || $stored === $this->current();
    }

    /** Stores the current domain: this is the shop (the admin's "Use <domain>", or the first save). */
    public function accept(): void
    {
        $current = $this->current();
        if ($current === '') {
            return;
        }
        $this->configWriter->save(self::PATH, $current);
        $this->reinitableConfig->reinit();
    }
}
