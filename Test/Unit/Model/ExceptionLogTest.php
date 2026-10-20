<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\LogDir;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

class ExceptionLogTest extends TestCase
{
    use LogDir;
    use Settings;

    private MemoryStore $store;
    private ExceptionLog $log;
    private RunState $state;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->state = new RunState($this->store);
        $this->log = new ExceptionLog($this->logReader($this->state,
            $this->config(['exceptions/max_read_kb' => '1'])));
    }

    protected function tearDown(): void
    {
        $this->removeLogDir();
    }

    private function read(): array
    {
        $this->state->begin();
        $entries = $this->log->newEntries();
        $this->assertSame($entries, $this->log->newEntries(), 'read once per run');
        $this->state->commit();
        return $entries;
    }

    public function testTheFirstRunOnlyStoresTheOffsetThenNewEntriesAreCounted(): void
    {
        $this->appendLog(self::entry('Old\\Exception', 'before'));
        $this->assertSame(0, $this->read()['entries']);
        $this->appendLog(self::entry('Magento\\Framework\\Exception\\LocalizedException', 'one')
            . self::entry('Magento\\Framework\\Exception\\LocalizedException', 'two')
            . "[2026-10-20T08:01:03.000000+00:00] main.CRITICAL: plain message without an exception [] []\n"
            . self::entry('Magento\\Framework\\Exception\\LocalizedException', 'mail',
                'Magento\\Framework\\Exception\\MailException'));
        $entries = $this->read();
        unset($entries['safe']);
        $this->assertSame(['entries' => 4, 'classes' => ['Magento\\Framework\\Exception\\LocalizedException' => 3,
            'other' => 1], 'mail' => 1, 'partial' => false, 'skipped' => 0], $entries);
        $this->assertSame(0, $this->read()['entries'], 'nothing new');
    }

    public function testAHalfWrittenEntryWaitsForTheNextRun(): void
    {
        $this->read();
        $this->appendLog(self::entry('A\\One', 'x') . '[2026-10-20T08:01:04.000000+00:00] main.CRITICAL: half');
        $this->assertSame(1, $this->read()['entries']);
        $this->appendLog(' {"exception":"[object] (B\\\\Two(code: 0): x at /a.php:1)"} []' . "\n");
        $this->assertSame(['B\\Two' => 1], $this->read()['classes']);
    }

    public function testRotationStartsTheNewFileFromItsBeginning(): void
    {
        $this->appendLog(str_repeat(self::entry('A\\One', 'x'), 3));
        $this->read();
        unlink($this->logDir . '/exception.log');
        $this->appendLog(self::entry('B\\Two', 'y'));
        $this->assertSame(['B\\Two' => 1], $this->read()['classes'], 'smaller file (or another inode)');
        unlink($this->logDir . '/exception.log');
        $this->assertSame(0, $this->read()['entries'], 'no file');
        $this->appendLog(self::entry('C\\Three', 'z'));
        $this->assertSame(['C\\Three' => 1], $this->read()['classes']);
    }

    public function testTheScanStopsAtItsTimeBudgetAndIsMarkedPartial(): void
    {
        $log = new ExceptionLog($this->logReader($this->state, $this->config(['exceptions/max_read_kb' => '64']), [],
            0.0));
        $this->state->begin();
        $log->newEntries();
        $this->state->commit();
        $this->appendLog(str_repeat(self::entry('A\\One', 'x'), 5));
        $this->state->begin();
        $entries = $log->newEntries();
        $this->state->commit();
        $this->assertSame([0, true], [$entries['entries'], $entries['partial']], 'no time left: nothing scanned');
    }

    public function testABacklogOverTheCapReadsTheNewestAndReportsWhatWasSkipped(): void
    {
        $this->read();
        $this->appendLog(str_repeat(self::entry('A\\One', 'x'), 20) . self::entry('Z\\Latest', 'y')); // ~5 KB, cap 1 KB
        $entries = $this->read();
        $this->assertTrue($entries['partial']);
        $this->assertArrayHasKey('Z\\Latest', $entries['classes'], 'the newest bytes, not the oldest');
        $this->assertGreaterThan(3000, $entries['skipped'], 'the bytes not read');
        $this->assertGreaterThan(0, $entries['entries']);
        $this->assertLessThan(20, $entries['entries']);
        $this->assertSame(filesize($this->logDir . '/exception.log'),
            $this->store->row('cursor:log:exception')['offset'], 'the rest is skipped: the next run starts at the end');
        $this->assertSame(0, $this->read()['entries']);
    }

    public function testOnlyClassNamesAreKept(): void
    {
        $this->read();
        $this->appendLog(self::entry('Vendor\\Payment\\DeclinedException', 'card of jane.doe@example.com declined')
            . '[2026-10-20T08:01:02.000000+00:00] main.CRITICAL: x'
            . ' {"exception":"[object] (evil class name!(code: 0)"} []'
            . "\n");
        $entries = $this->read();
        $this->assertSame(['Vendor\\Payment\\DeclinedException' => 1, 'other' => 1], $entries['classes']);
        $this->assertStringNotContainsString('jane', json_encode($this->store->rows));
    }

    public function testALogCutBackInPlaceDoesNotCountItsOldLinesAgain(): void
    {
        $this->logClock = new \CommerceKings\AppHealth\Test\Unit\Stub\Clock(1792483260); // 08:01
        $this->log = new ExceptionLog($this->logReader($this->state,
            $this->config(['exceptions/max_read_kb' => '64'])));
        $this->appendLog(str_repeat(self::entry('A\\Old', 'x'), 3));
        $this->read();
        $this->appendLog(self::entry('B\\New', 'y'));
        $this->assertSame(1, $this->read()['entries']);
        // copytruncate-style: cut back in place, the same inode, still holding old lines - plus one new
        $file = $this->logDir . '/exception.log';
        $handle = fopen($file, 'r+');
        ftruncate($handle, 0);
        fclose($handle);
        $this->logClock->now += 300;
        $this->appendLog(str_replace('2026-10-20T08:01:02', '2026-10-20T06:00:00', str_repeat(self::entry('A\\Old',
            'x'), 2)) . str_replace('08:01:02', '08:05:30', self::entry('C\\Newer', 'z')));
        $entries = $this->read();
        $this->assertSame(['C\\Newer' => 1], $entries['classes'], 'the 06:00 lines are old, not a spike');
    }
}
