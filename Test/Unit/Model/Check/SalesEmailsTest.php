<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\SalesEmails;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class SalesEmailsTest extends TestCase
{
    use Db;
    use Settings;

    private const NOW = 1792483200;

    /** @return array<int, CheckResult> */
    private function check(array $rows): array
    {
        $stores = [];
        foreach ([1 => 1, 2 => 1, 3 => 2] as $id => $websiteId) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[] = $store;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn($stores);
        $scopeConfig = $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        return (new SalesEmails($this->resource($this->db(['fetchAll' => [$rows]])), $manager,
            $this->config(['sales_emails/unsent_count' => '3', 'sales_emails/unsent_minutes' => '30']),
            new Clock(self::NOW), $scopeConfig))->runForWebsites([1, 2]);
    }

    public function testUnsentEmailsPerWebsite(): void
    {
        $at = fn (int $ago) => gmdate('Y-m-d H:i:s', self::NOW - $ago);
        $results = $this->check([
            ['entity' => 'order', 'store_id' => '1', 'count' => '2', 'oldest' => $at(3600)],
            ['entity' => 'order', 'store_id' => '2', 'count' => '1', 'oldest' => $at(5400)],
            ['entity' => 'invoice', 'store_id' => '2', 'count' => '1', 'oldest' => $at(2000)],
            ['entity' => 'order', 'store_id' => '3', 'count' => '2', 'oldest' => $at(9000)],
        ]);
        $this->assertSame(CheckResult::FAIL, $results[1]->status);
        $this->assertSame('4 sales emails not sent after 30 min (order x3, invoice x1)', $results[1]->detail);
        $this->assertSame('4 unsent (order x3, invoice x1), the oldest from {t:' . (self::NOW - 5400) . '}',
            $results[1]->causes[0]->detail);
        $this->assertStringContainsString('sales_send_order_emails cron', $results[1]->causes[0]->hint,
            'asynchronous sending');
        $this->assertSame(['unsent' => 4], $results[1]->gauges);
        $this->assertSame(CheckResult::OK, $results[2]->status, 'under the limit on website 2');
    }

    public function testEachTableIsABoundedCreatedAtRange(): void
    {
        $this->check([]);
        $from = array_filter($this->calls, fn ($call) => $call[0] === 'from' && is_string($call[1]));
        $this->assertSame(['sales_order', 'sales_invoice', 'sales_shipment', 'sales_creditmemo'],
            array_values(array_map(fn ($call) => $call[1], $from)));
        $wheres = $this->wheres();
        $this->assertSame(4, count(array_keys($wheres, 'created_at >= ?', true)));
        $this->assertSame(4, count(array_keys($wheres, 'created_at < ?', true)));
        $this->assertSame(4, count(array_keys($wheres, 'COALESCE(email_sent, 0) <= 0', true)),
            'only the created_at index can bound the rows');
        $created = array_values(array_filter($this->calls, fn ($call) => $call[0] === 'where'
            && in_array($call[1], ['created_at >= ?', 'created_at < ?'], true)));
        $this->assertSame([gmdate('Y-m-d H:i:s', self::NOW - 24 * 3600), gmdate('Y-m-d H:i:s', self::NOW - 1800)],
            [$created[0][2], $created[1][2]]);
        $this->assertSame(4, count(array_filter($this->calls, fn ($call) => $call[0] === 'limit' && $call[1] === 100)));
    }
}
