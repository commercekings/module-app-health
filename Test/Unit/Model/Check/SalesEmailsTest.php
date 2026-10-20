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
        return (new SalesEmails($this->resource($this->db(['fetchAll' => [$rows]])), $manager,
            $this->config(['sales_emails/unsent_count' => '3', 'sales_emails/unsent_minutes' => '30']),
            new Clock(self::NOW)))->runForWebsites([1, 2]);
    }

    public function testUnsentEmailsPerWebsite(): void
    {
        $results = $this->check([
            ['entity' => 'order', 'store_id' => '1', 'count' => '2'],
            ['entity' => 'order', 'store_id' => '2', 'count' => '1'],
            ['entity' => 'invoice', 'store_id' => '2', 'count' => '1'],
            ['entity' => 'order', 'store_id' => '3', 'count' => '2'],
        ]);
        $this->assertSame(CheckResult::FAIL, $results[1]->status);
        $this->assertSame('4 sales emails not sent after 30 min (order x3, invoice x1)', $results[1]->detail);
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
