<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\Exceptions;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\LogDir;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    use LogDir;
    use Settings;

    private RunState $state;
    private Exceptions $check;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->state = new RunState(new MemoryStore());
        $this->clock = new Clock();
        $config = $this->config(['exceptions/count' => '5', 'exceptions/window_minutes' => '15',
            'exceptions/max_read_kb' => '1024']);
        $this->check = new Exceptions(new ExceptionLog($this->logReader($this->state, $config)),
            new Fingerprints($this->state, $config), $config, $this->state, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeLogDir();
    }

    private function run5(string $append = ''): CheckResult
    {
        $this->appendLog($append);
        $this->state->begin();
        $result = $this->check->run();
        $this->state->commit();
        $this->clock->now += 300;
        return $result;
    }

    public function testASpikeWithinTheWindowFailsWithTheTopClasses(): void
    {
        $this->run5(self::entry('Old\\X', 'before the first run'));
        $first = $this->run5(str_repeat(self::entry('A\\PaymentException', 'p'), 2) . self::entry('B\\NoSuch', 'n'));
        $this->assertSame(CheckResult::OK, $first->status);
        $this->assertSame(['exceptions' => 3, 'exceptions:A\\PaymentException' => 2, 'exceptions:B\\NoSuch' => 1],
            array_filter($first->counts, fn ($key) => !str_starts_with($key, 'fp:'), ARRAY_FILTER_USE_KEY));
        $this->assertSame([2, 1], array_values(array_filter($first->counts,
            fn ($key) => str_starts_with($key, 'fp:'), ARRAY_FILTER_USE_KEY)), 'by fingerprint, for the summary');
        $this->assertCount(2, $first->notices, 'two exceptions never seen before');
        $this->assertSame("Critical: PaymentException (2×, {t:1792483262})\nWhere: vendor/magento/x.php:12",
            $first->notices[0], 'the entry\'s own time');
        $spike = $this->run5(self::entry('A\\PaymentException', 'p') . self::entry('C\\Other', 'o'));
        $this->assertSame(CheckResult::FAIL, $spike->status);
        $this->assertSame('5 exceptions in 15 min (PaymentException x3, NoSuch x1, Other x1)', $spike->detail);
        $this->assertCount(1, $spike->notices, 'only C is new');
        $this->assertSame('Exception spike', $spike->title);
        $this->assertSame(CheckResult::FAIL, $this->run5()->status, 'still within the window');
        $this->assertSame(CheckResult::OK, $this->run5()->status, 'the first entries left the window');
    }
}
