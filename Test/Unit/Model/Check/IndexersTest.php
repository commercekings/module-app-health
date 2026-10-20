<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\Indexers;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\Indexer\ConfigInterface;
use PHPUnit\Framework\TestCase;

class IndexersTest extends TestCase
{
    use Db;
    use Settings;

    private const NOW = 1792483200;

    private function check(
        array $rows,
        array $indexers = ['catalog_product_price' => ['view_id' => 'catalog_product_price_view'],
            'catalogsearch_fulltext' => ['view_id' => 'catalogsearch_fulltext_view']]
    ): CheckResult
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getIndexers')->willReturn($indexers);
        $pairs = [['catalog_product_price_view' => 'enabled'], ['indexer_reindex_all_invalid' => gmdate('Y-m-d H:i:s',
            self::NOW - 600)]];
        return (new Indexers($this->resource($this->db(['fetchAll' => [$rows], 'fetchPairs' => $pairs])), $config,
            $this->config(['indexers/minutes' => '60']), new Clock(self::NOW)))->run();
    }

    private function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW - $seconds);
    }

    public function testInvalidOrWorkingForOverTheLimitFails(): void
    {
        $result = $this->check([
            ['indexer_id' => 'catalog_product_price', 'status' => 'invalid', 'updated' => $this->ago(7200)],
            ['indexer_id' => 'catalogsearch_fulltext', 'status' => 'working', 'updated' => $this->ago(3 * 3600)],
        ]);
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('catalog_product_price invalid for 2 h, catalogsearch_fulltext working for 3 h',
            $result->detail);
        $this->assertContains('indexer_id IN (?)', $this->wheres(), 'only indexers Magento still has');
    }

    public function testRecentlyInvalidatedIsStillOk(): void
    {
        $this->assertSame(CheckResult::OK, $this->check([
            ['indexer_id' => 'catalog_product_price', 'status' => 'invalid', 'updated' => $this->ago(59 * 60)],
        ])->status);
        $this->assertSame(CheckResult::OK, $this->check([])->status);
    }

    public function testNeverUpdatedCountsAsLongAndTheListIsShort(): void
    {
        $rows = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $id) {
            $rows[] = ['indexer_id' => $id, 'status' => 'invalid', 'updated' => null];
        }
        $this->assertSame('a invalid for long, b invalid for long, c invalid for long, +2 more',
            $this->check($rows)->detail);
    }

    public function testNoIndexersNoQuery(): void
    {
        $this->assertSame(CheckResult::OK, $this->check([], [])->status);
        $this->assertSame([], $this->calls);
    }

    public function testCausesSayWhichSinceWhenItsModeAndTheReindexCron(): void
    {
        $result = $this->check([
            ['indexer_id' => 'catalog_product_price', 'status' => 'invalid', 'updated' => $this->ago(7200)],
            ['indexer_id' => 'catalogsearch_fulltext', 'status' => 'working', 'updated' => $this->ago(3 * 3600)],
        ]);
        $causes = array_map(fn ($c) => [$c->code, $c->detail], $result->causes);
        $this->assertSame([
            ['indexer_invalid', 'catalog_product_price invalid since {t:' . (self::NOW - 7200) . '}'
                . ' (update by schedule)'],
            ['indexer_stuck', 'catalogsearch_fulltext still working since {t:' . (self::NOW - 10800)
                . '} (update on save): a reindex may have crashed'],
            ['indexer_cron', 'indexer_reindex_all_invalid last ran at {t:' . (self::NOW - 600) . '}'],
            ['indexer_cron', 'indexer_update_all_views has not run in 24 h'],
        ], $causes);
        $this->assertStringContainsString('indexer:reindex catalog_product_price', $result->causes[0]->hint);
    }
}
