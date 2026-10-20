<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\Maintenance;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\App\MaintenanceMode;
use PHPUnit\Framework\TestCase;

class MaintenanceTest extends TestCase
{
    use Settings;

    private MemoryStore $store;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->clock = new Clock();
    }

    private function check(bool $on): CheckResult
    {
        $mode = $this->createStub(MaintenanceMode::class);
        $mode->method('isOn')->willReturn($on);
        $state = new RunState($this->store);
        $state->begin();
        $result = (new Maintenance($mode, $this->config(['maintenance/minutes' => '15']), $state, $this->clock))->run();
        $state->commit();
        return $result;
    }

    public function testOnForOverTheLimitFailsUntilItIsOff(): void
    {
        $this->assertSame(CheckResult::OK, $this->check(true)->status, 'a deployment takes a few minutes');
        $this->clock->now += 15 * 60;
        $result = $this->check(true);
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('on for 15 min', $result->detail);
        $this->assertSame(CheckResult::OK, $this->check(false)->status);
        $this->clock->now += 60;
        $this->assertSame(CheckResult::OK, $this->check(true)->status, 'on again: timed from now');
    }

    public function testOffWritesNothing(): void
    {
        $this->check(false);
        $this->check(false);
        $this->assertSame(0, $this->store->statements - 2, 'only the two loads');
    }
}
