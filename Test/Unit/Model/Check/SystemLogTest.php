<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\SystemLog;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\LogDir;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

class SystemLogTest extends TestCase
{
    use LogDir;
    use Settings;

    private MemoryStore $store;
    private RunState $state;
    private SystemLog $check;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->state = new RunState($this->store);
        $this->clock = new Clock();
        $config = $this->config(['system_log/error_count' => '3', 'system_log/window_minutes' => '5',
            'system_log/max_read_kb' => '1024']);
        $this->check = new SystemLog($this->logReader($this->state, $config), new Fingerprints($this->state, $config),
            $config, $this->state, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeLogDir();
    }

    private function run5(string $append = '', int $minutes = 5): CheckResult
    {
        $this->appendLog($append, 'system.log');
        $this->state->begin();
        $result = $this->check->run();
        $this->state->commit();
        $this->clock->now += $minutes * 60;
        return $result;
    }

    public function testLevelsNewErrorsOnceAndASpike(): void
    {
        $this->run5(self::line('ERROR', 'before the first run'));
        $first = $this->run5(self::line('INFO', 'Cron Job x is run') . self::line('WARNING', 'Slow 1200 ms')
            . self::line('ERROR', 'Unable to send order 1234') . self::line('ERROR', 'Unable to send order 99'), 1);
        $this->assertSame(['critical' => 0, 'errors' => 2, 'warnings' => 1],
            array_intersect_key($first->counts, ['critical' => 1, 'errors' => 1, 'warnings' => 1]));
        $this->assertSame(CheckResult::OK, $first->status);
        $this->assertCount(1, $first->notices, 'one error, twice');
        $this->assertMatchesRegularExpression('/^🆕 🟠 Unable to send order \*\*\* ×2 · \{t:\d+\}'
            . ' · #[0-9a-f]{8}$/u',
            $first->notices[0]);
        $again = $this->run5(self::line('ERROR', 'Unable to send order 5'));
        $this->assertSame([], $again->notices, 'seen before');
        $this->assertSame(CheckResult::FAIL, $again->status, '3 errors within 5 minutes');
        $this->assertSame('Error spike', $again->title);
        $this->assertMatchesRegularExpression('/^3 errors in 5 min \(Unable to send order \*\*\* #[0-9a-f]{8} x3\)$/',
            $again->detail);
        $this->assertSame(CheckResult::OK, $this->run5()->status, 'the window passed');
    }

    public function testACriticalIsToldAgainAfterTheRepeatInterval(): void
    {
        $this->run5();
        $this->assertCount(1, $this->run5(self::line('CRITICAL', 'Payment gateway down'))->notices);
        $this->assertSame([], $this->run5(self::line('CRITICAL', 'Payment gateway down'))->notices);
        $this->clock->now += 6 * 3600;
        $again = $this->run5(self::line('CRITICAL', 'Payment gateway down'));
        $this->assertCount(1, $again->notices);
        $this->assertStringStartsWith('🔴 Payment gateway down ×1', $again->notices[0], 'not "NEW"');
    }

    public function testTheFingerprintMemoryIsBounded(): void
    {
        $this->run5();
        $lines = '';
        for ($i = 0; $i < Fingerprints::LIMIT + 20; $i++) {
            $kind = str_repeat('a', 1 + $i % 26) . ' ' . chr(97 + intdiv($i, 26));
            $lines .= self::line('ERROR', 'Error kind ' . $kind);
        }
        $result = $this->run5($lines);
        $this->assertCount(Fingerprints::LIMIT, $this->store->row(Fingerprints::KEY));
        $this->assertCount(SystemLog::NOTICES + 1, $result->notices, 'five, then "+N more"');
        $this->assertStringStartsWith('+515 more', $result->notices[SystemLog::NOTICES]);
    }
}
