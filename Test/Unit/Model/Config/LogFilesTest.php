<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Config;

use CommerceKings\AppHealth\Model\Config\Backend\ImmediateLogFiles;
use CommerceKings\AppHealth\Model\Config\Backend\LogFiles;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * *Extra log files* and *Errors at once from* refuse on save what App health would silently leave out: a sixth file,
 * system.log or exception.log as an extra, two names that are one source, a file at once that is not read.
 */
class LogFilesTest extends TestCase
{
    /**
     * @param class-string<LogFiles> $class
     * @param array<string, string>|null $posted the group's fields saved with it (fieldset_data)
     */
    private function save(string $class, string $value, ?array $posted = null, string $savedExtra = ''): string
    {
        $context = $this->createConfiguredStub(Context::class, [
            'getEventDispatcher' => $this->createStub(\Magento\Framework\Event\ManagerInterface::class),
        ]);
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([['commercekings_app_health/system_log/extra_files', 'default',
            null, $savedExtra]]);
        $field = new $class($context, $this->createStub(Registry::class), $config,
            $this->createStub(TypeListInterface::class));
        $field->setValue($value);
        if ($posted !== null) {
            $field->setData('fieldset_data', $posted);
        }
        $field->beforeSave();
        return (string)$field->getValue();
    }

    private function refusal(string $class, string $value, ?array $posted = null, string $savedExtra = ''): string
    {
        try {
            $this->save($class, $value, $posted, $savedExtra);
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }
        $this->fail('saved ' . $value);
    }

    public function testExtraFilesAtMostFiveOtherFilesEachItsOwnSource(): void
    {
        $this->assertSame("payment.log\nerp-sync.log", $this->save(LogFiles::class, 'payment.log, erp-sync.log'
            . "\npayment.log"));
        $this->assertSame('', $this->save(LogFiles::class, ''));
        $this->assertSame('Extra log files: at most 5 files (6 given).',
            $this->refusal(LogFiles::class, 'a.log b.log c.log d.log e.log f.log'));
        $this->assertSame('Extra log files: system.log is always read; list other files only.',
            $this->refusal(LogFiles::class, 'payment.log system.log'));
        $this->assertSame('Extra log files: "Payment.log" and "payment.log" would be one source (file_payment); keep'
            . ' one.', $this->refusal(LogFiles::class, 'Payment.log payment.log'));
        $long = str_repeat('a', 27);
        $this->assertStringContainsString('would be one source (file_' . $long . ')',
            $this->refusal(LogFiles::class, $long . '-one.log ' . $long . '-two.log'));
        $this->assertStringContainsString('is not a log file name', $this->refusal(LogFiles::class, '../x.log'));
    }

    public function testErrorsAtOnceOnlyFromFilesAppHealthReads(): void
    {
        $this->assertSame("system.log\nexception.log", $this->save(ImmediateLogFiles::class,
            'system.log exception.log', []));
        $this->assertSame('payment.log', $this->save(ImmediateLogFiles::class, 'payment.log',
            ['extra_files' => "erp.log\npayment.log"]), 'an extra file saved with it');
        $this->assertSame('payment.log', $this->save(ImmediateLogFiles::class, 'payment.log', null, 'payment.log'),
            'an extra file configured before (e.g. bin/magento config:set)');
        $this->assertSame('Errors at once from: App health does not read payment.log; add it to Extra log files first'
            . ' (or use system.log or exception.log).', $this->refusal(ImmediateLogFiles::class, 'payment.log',
            ['extra_files' => ''], 'payment.log'), 'what is saved with it counts, not the old value');
        $this->assertStringContainsString('is not a log file name', $this->refusal(ImmediateLogFiles::class,
            'debug.log'));
    }
}
