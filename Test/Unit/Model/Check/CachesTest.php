<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\Caches;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class CachesTest extends TestCase
{
    use Settings;

    private const NOW = 1792483200;

    private MemoryStore $store;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->clock = new Clock(self::NOW);
    }

    /** @param array<string, int> $statuses */
    private function check(array $statuses, array $invalidated): CheckResult
    {
        $types = $this->createStub(TypeListInterface::class);
        $types->method('getTypes')
            ->willReturn(array_map(fn ($status) => new DataObject(['status' => $status]), $statuses));
        $types->method('getInvalidated')->willReturn(array_fill_keys($invalidated, new DataObject()));
        $state = new RunState($this->store);
        $state->begin();
        $result = (new Caches($types, $this->config(['caches/minutes' => '60']), $state, $this->clock))->run([])[0];
        $state->commit();
        return $result;
    }

    public function testADisabledTypeFailsAtOnce(): void
    {
        $result = $this->check(['config' => 1, 'full_page' => 0, 'block_html' => 0], []);
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('block_html and full_page disabled', $result->detail);
        $this->assertSame('cache_disabled', $result->causes[0]->code);
        $this->assertStringContainsString('cache:enable block_html full_page', $result->causes[0]->hint);
    }

    public function testAnInvalidatedTypeWarnsAfterTheLimitFromWhenItWasFirstSeen(): void
    {
        $this->assertSame(CheckResult::OK, $this->check(['config' => 1], ['config'])->status);
        $this->clock->now += 59 * 60;
        $this->assertSame(CheckResult::OK, $this->check(['config' => 1], ['config'])->status);
        $this->clock->now += 60;
        $result = $this->check(['config' => 1], ['config']);
        $this->assertSame(CheckResult::WARN, $result->status);
        $this->assertSame('config invalidated for 60 min', $result->detail);
        $this->assertSame(['cache_invalidated', 'config invalidated since {t:' . self::NOW . '}'],
            [$result->causes[0]->code, $result->causes[0]->detail]);
        $this->assertSame(CheckResult::OK, $this->check(['config' => 1], [])->status, 'refreshed');
        $this->assertSame(['since' => []], $this->store->row('cursor:caches'));
    }
}
