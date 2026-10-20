<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\Cause;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use CommerceKings\AppHealth\Model\Format;

/**
 * Per website: "Disable Email Communications" (system/smtp/disable) on for any of its store views (fail), whatever the
 * scope it was set in, from the configuration Magento already holds (also env.php and config.php).
 */
class EmailDisabled implements CheckInterface
{
    public const CODE = 'email_disabled';
    private const PATH = 'system/smtp/disable';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource
    ) {
    }

        /** What to do, for a problem without a hint of its own. */
    private function hint(): string
    {
        return (string)__('Stores > Configuration > Advanced > System > Mail Sending Settings >'
            . ' Disable Email Communications.');
    }

    public function getLabel(): string
    {
        return 'Email sending';
    }

    public function run(array $websiteIds): array
    {
        return array_map(fn (CheckResult $result) => $result->withHint($this->hint()), $this->check($websiteIds));
    }

    /**
     * @param int[] $websiteIds
     * @return array<int, CheckResult> by website id
     */
    private function check(array $websiteIds): array
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
        $where = null;
        foreach ($websiteIds as $websiteId) {
            if ($off[$websiteId] === 0) {
                $results[$websiteId] = CheckResult::ok();
                continue;
            }
            $where ??= $this->where();
            $results[$websiteId] = CheckResult::fail(sprintf('emails are disabled in %d of %d store views',
                $off[$websiteId], $views[$websiteId]), [], 'Emails disabled')
                ->withCauses([new Cause('email_disabled_setting', 'Disable Email Communications is set to Yes '
                    . ($where !== '' ? $where : 'in app/etc/env.php or config.php'), (string)__(
                        'Stores > Configuration > Advanced > System > Mail Sending Settings: set it to No in that'
                        . ' scope (or remove it from env.php).'
                    ))]);
        }
        return $results;
    }

    /** "in the default scope, website 1 and store view 3", from core_config_data (read only when it is on). */
    private function where(): string
    {
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()->from($this->resource->getTableName('core_config_data'),
            ['scope', 'scope_id'])->where('path = ?', self::PATH)->where('value = ?', '1')->limit(20));
        $scopes = array_map(fn ($row) => match ((string)$row['scope']) {
            'websites' => 'website ' . (int)$row['scope_id'],
            'stores' => 'store view ' . (int)$row['scope_id'],
            default => 'the default scope',
        }, $rows);
        return $scopes === [] ? '' : 'in ' . Format::list(array_values(array_unique($scopes)));
    }
}
