<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Api\WebsiteCheckInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Per website: "Disable Email Communications" (system/smtp/disable) on for any of its store views (fail), whatever the
 * scope it was set in, from the configuration Magento already holds (also env.php and config.php).
 */
class EmailDisabled implements WebsiteCheckInterface
{
    public const CODE = 'email_disabled';
    private const PATH = 'system/smtp/disable';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getHint(): string
    {
        return (string)__('Stores > Configuration > Advanced > System > Mail Sending Settings >'
            . ' Disable Email Communications.');
    }

    public function getLabel(): string
    {
        return 'Email sending';
    }

    public function runForWebsites(array $websiteIds): array
    {
        $views = array_fill_keys($websiteIds, 0);
        $off = array_fill_keys($websiteIds, 0);
        foreach ($this->storeManager->getStores() as $store) {
            $websiteId = (int)$store->getWebsiteId();
            if (!isset($views[$websiteId])) {
                continue;
            }
            $views[$websiteId]++;
            $off[$websiteId] += (int)$this->scopeConfig->isSetFlag(self::PATH, ScopeInterface::SCOPE_STORE,
                (int)$store->getId());
        }
        $results = [];
        foreach ($websiteIds as $websiteId) {
            $results[$websiteId] = $off[$websiteId] === 0 ? CheckResult::ok()
                : CheckResult::fail(sprintf('emails are disabled in %d of %d store views (Stores > Configuration >'
                    . ' Advanced > System > Mail Sending Settings)', $off[$websiteId], $views[$websiteId]), [],
                    'Emails disabled');
        }
        return $results;
    }
}
