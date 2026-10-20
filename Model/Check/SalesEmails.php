<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\Data\Cause;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Per website: "unsent_count" or more sales emails (orders, invoices, shipments, credit memos) meant to be sent
 * (send_email = 1) without email_sent after "unsent_minutes" (fail). One query: per table a range of the last
 * LOOKBACK_HOURS on its created_at index, at most CAP rows, counted by store view.
 */
class SalesEmails implements CheckInterface
{
    public const CODE = 'sales_emails';
    public const LOOKBACK_HOURS = 24;
    private const CAP = 100;
    private const TABLES = ['order' => 'sales_order', 'invoice' => 'sales_invoice', 'shipment' => 'sales_shipment',
        'credit memo' => 'sales_creditmemo'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly DateTime $dateTime,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

        /** What to do, for a problem without a hint of its own. */
    private function hint(): string
    {
        return (string)__('Is the sales email cron running (async sending), and does the mail server accept mail?');
    }

    public function getLabel(): string
    {
        return 'Sales emails';
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
        $minutes = $this->config->getInt(self::CODE, 'unsent_minutes', 30);
        $threshold = $this->config->getInt(self::CODE, 'unsent_count', 3);
        $websiteOf = [];
        foreach ($this->storeManager->getStores() as $store) {
            $websiteOf[(int)$store->getId()] = (int)$store->getWebsiteId();
        }
        $unsent = array_fill_keys($websiteIds, []);
        $oldest = [];
        foreach ($this->unsent($minutes) as $row) {
            $websiteId = $websiteOf[(int)$row['store_id']] ?? null;
            if ($websiteId !== null && isset($unsent[$websiteId])) {
                $unsent[$websiteId][$row['entity']] = ($unsent[$websiteId][$row['entity']] ?? 0) + (int)$row['count'];
                $time = (int)strtotime($row['oldest'] . ' UTC');
                $oldest[$websiteId] = min($oldest[$websiteId] ?? $time, $time);
            }
        }
        $async = $this->scopeConfig->isSetFlag('sales_email/general/async_sending');
        $results = [];
        foreach ($unsent as $websiteId => $counts) {
            $total = array_sum($counts);
            $gauges = ['unsent' => $total] + ($total > 0 ? ['oldest' => $oldest[$websiteId] ?? 0] : []);
            $results[$websiteId] = $total < $threshold
                ? new CheckResult(CheckResult::OK, '', [], [], '', $gauges)
                : new CheckResult(CheckResult::FAIL, sprintf('%d sales emails not sent after %s (%s)', $total,
                    Format::duration($minutes * 60), Format::top($counts)), [], [], 'Sales emails not sent',
                    $gauges, [new Cause('sales_emails_unsent', sprintf('%d unsent (%s), the oldest'
                    . ' from %s', $total, Format::top($counts), Format::at($oldest[$websiteId] ?? 0)), $async
                    ? (string)__('Asynchronous sending is on: is the sales_send_order_emails cron running? Then the'
                        . ' mail server (see Sending).')
                    : (string)__('They were refused when the order was placed: the mail server or provider (see'
                        . ' Sending, and var/log/system.log).'))]);
        }
        return $results;
    }

    /** @return array<int, array<string, mixed>> entity, store_id, count, oldest */
    private function unsent(int $minutes): array
    {
        $db = $this->resource->getConnection();
        $now = $this->dateTime->gmtTimestamp();
        $from = gmdate('Y-m-d H:i:s', $now - self::LOOKBACK_HOURS * 3600);
        $to = gmdate('Y-m-d H:i:s', $now - $minutes * 60);
        $parts = [];
        foreach (self::TABLES as $entity => $table) {
            $rows = $db->select()->from($this->resource->getTableName($table), ['store_id', 'created_at'])
                ->where('created_at >= ?', $from)->where('created_at < ?', $to)
                // COALESCE keeps the send_email and email_sent indexes out: an email_sent range has no time bound
                // (old unsent rows); async sending counts failed attempts up from a negative number, null = never tried
                ->where('COALESCE(send_email, 0) = 1')
                ->where('COALESCE(email_sent, 0) <= 0')
                ->limit(self::CAP);
            if ($entity === 'order') {
                // a cancelled or closed order's confirmation no longer matters; COALESCE keeps the state index out
                $rows->where("COALESCE(state, '') NOT IN ('canceled', 'closed')");
            }
            $parts[] = $db->select()->from(['t' => $rows], ['entity' => new Expression($db->quote($entity)),
                'store_id', 'count' => new Expression('COUNT(*)'), 'oldest' => new Expression('MIN(created_at)')])
                ->group('store_id');
        }
        $union = $db->select();
        $union->union($parts, Select::SQL_UNION_ALL);
        return $db->fetchAll($union);
    }
}
