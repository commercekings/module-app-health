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
        $result = $this->check->run([])[0];
        $this->state->commit();
        $this->clock->now += $minutes * 60;
        return $result;
    }

    public function testABacklogOverTheCapCountsTheNewestAndSaysHowMuchWasNotRead(): void
    {
        $config = $this->config(['system_log/error_count' => '3', 'system_log/window_minutes' => '5',
            'system_log/max_read_kb' => '1']);
        $this->check = new SystemLog($this->logReader($this->state, $config), new Fingerprints($this->state, $config),
            $config, $this->state, $this->clock);
        $this->run5();
        $result = $this->run5(str_repeat(self::line('ERROR', 'Unable to send order 5'), 40)); // ~5 KB
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertMatchesRegularExpression('/^at least \d+ errors in 5 min \(Unable to send x\d+\); \d KB of the'
            . ' log not read$/', $result->detail);
        $this->assertGreaterThan(0, $result->gauges['skipped kb']);
    }

    public function testLevelsNewCriticalsAtOnceErrorsForTheHourlyStatusAndASpike(): void
    {
        $this->run5(self::line('ERROR', 'before the first run'));
        $first = $this->run5(self::line('INFO', 'Cron Job x is run') . self::line('WARNING', 'Slow 1200 ms')
            . self::line('ERROR', 'Unable to send order 1234') . self::line('ERROR', 'Unable to send order 99'), 1);
        $this->assertSame(['critical' => 0, 'errors' => 2, 'warnings' => 1],
            array_intersect_key($first->counts, ['critical' => 1, 'errors' => 1, 'warnings' => 1]));
        $this->assertSame(CheckResult::OK, $first->status);
        $this->assertSame([], $first->notices, 'an error: not at once');
        $errors = $this->store->row(Fingerprints::ERRORS_KEY);
        $this->assertCount(1, $errors, 'one error, twice: for the hourly status');
        $this->assertSame([2, 1], [$errors[0][2], $errors[0][3]], 'its count in the run, new');
        $critical = $this->run5(self::line('CRITICAL', 'Payment gateway down 77'), 1);
        $this->assertCount(2, $critical->notices, 'a new critical: at once, with what to do');
        $this->assertMatchesRegularExpression('/^Critical: Payment gateway down \(\{t:\d+\}\)$/u',
            $critical->notices[0]);
        $again = $this->run5(self::line('ERROR', 'Unable to send order 5'));
        $this->assertSame([], $again->notices);
        $this->assertSame(0, $this->store->row(Fingerprints::ERRORS_KEY)[1][3], 'seen before: not new');
        $this->assertSame(CheckResult::FAIL, $again->status, '3 errors within 5 minutes');
        $this->assertSame('Error spike', $again->title);
        $this->assertMatchesRegularExpression('/^4 errors in 5 min \(Unable to send x3, Payment gateway down x1\)$/',
            $again->detail, 'errors and criticals');
        $this->assertSame(CheckResult::OK, $this->run5()->status, 'the window passed');
    }

    public function testACriticalIsToldAgainAfterTheRepeatInterval(): void
    {
        $this->run5();
        $this->assertCount(2, $this->run5(self::line('CRITICAL', 'Payment gateway down'))->notices, 'and what to do');
        $this->assertSame([], $this->run5(self::line('CRITICAL', 'Payment gateway down'))->notices);
        $this->clock->now += 6 * 3600;
        $again = $this->run5(self::line('CRITICAL', 'Payment gateway down'));
        $this->assertCount(2, $again->notices);
        $this->assertStringStartsWith('Critical: Payment gateway down ({t:', $again->notices[0], 'not "NEW"');
    }

    public function testAnErrorDroppedFromTheMemoryIsNotNewAgainWithinADay(): void
    {
        $this->run5();
        $this->assertCount(2, $this->run5(self::line('CRITICAL', 'Payment gateway down'))->notices, 'new, what to do');
        $fp = (string)array_key_first(array_filter($this->store->row(Fingerprints::KEY),
            fn ($e) => $e['level'] === 'CRITICAL'));
        $lines = '';
        for ($i = 0; $i < Fingerprints::LIMIT + 5; $i++) {
            $kind = str_repeat('a', 1 + $i % 26) . ' ' . chr(97 + intdiv($i, 26));
            $lines .= self::line('WARNING', 'Slow kind ' . $kind);
        }
        $this->run5($lines); // newer warnings push it out of the 500
        $this->assertArrayNotHasKey($fp, $this->store->row(Fingerprints::KEY), 'pushed out of the 500');
        $this->assertSame([], $this->run5(self::line('CRITICAL', 'Payment gateway down'))->notices,
            'seen within the day: not "new" again');
    }

    public function testTheFingerprintMemoryIsBounded(): void
    {
        $this->run5();
        $lines = '';
        for ($i = 0; $i < Fingerprints::LIMIT + 20; $i++) {
            $kind = str_repeat('a', 1 + $i % 26) . ' ' . chr(97 + intdiv($i, 26));
            $lines .= self::line('CRITICAL', 'Error kind ' . $kind);
        }
        $result = $this->run5($lines);
        $this->assertCount(Fingerprints::LIMIT, $this->store->row(Fingerprints::KEY));
        $this->assertCount(SystemLog::NOTICES + 2, $result->notices, 'five, then "+N more", then what to do');
        $this->assertStringStartsWith('…and 515 more', $result->notices[SystemLog::NOTICES]);
    }
}
