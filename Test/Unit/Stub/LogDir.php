<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Log\FileLogSource;
use CommerceKings\AppHealth\Model\Log\LogReader;
use CommerceKings\AppHealth\Model\Log\LogSourcePool;
use CommerceKings\AppHealth\Model\Log\MonologLineParser;
use CommerceKings\AppHealth\Model\Log\Normalizer;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\ReadFactory;

/** A temporary var/log with Magento's real directory reader. */
trait LogDir
{
    private string $logDir = '';
    private Filesystem $filesystem;
    /** The reader's clock; the default Clock when unset. */
    private ?\Magento\Framework\Stdlib\DateTime\DateTime $logClock = null;

    private function logFilesystem(): Filesystem
    {
        if ($this->logDir !== '') {
            return $this->filesystem;
        }
        $this->logDir = sys_get_temp_dir() . '/ck-app-health-' . bin2hex(random_bytes(4));
        mkdir($this->logDir);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')
            ->willReturn(new Read(new ReadFactory(new DriverPool()), new File(), $this->logDir));
        return $this->filesystem = $filesystem;
    }

    private function appendLog(string $text, string $file = 'exception.log'): void
    {
        file_put_contents($this->logDir . '/' . $file, $text, FILE_APPEND);
    }

    /**
     * The reader over a temporary var/log: system.log for "system_log", exception.log for "exceptions", Magento's root
     * at /var/www/html; $extra sources are added to the pool (a third party's).
     */
    private function logReader(RunState $state, Config $config, array $extra = []): LogReader
    {
        $filesystem = $this->logFilesystem();
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $monolog = new MonologLineParser();
        $pool = new LogSourcePool($config, $filesystem, $scopeConfig, $monolog, array_merge([
            ['source' => new FileLogSource($filesystem, $scopeConfig, 'system', 'system.log'), 'parser' => $monolog,
                'check' => 'system_log'],
            ['source' => new FileLogSource($filesystem, $scopeConfig, 'exception', 'exception.log'),
                'parser' => $monolog, 'check' => 'exceptions'],
        ], $extra));
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn('/var/www/html');
        return new LogReader($pool, new Normalizer(), $state, $config, $directoryList,
            $this->logClock ?? new \CommerceKings\AppHealth\Test\Unit\Stub\Clock());
    }

    private function removeLogDir(): void
    {
        if ($this->logDir !== '' && is_dir($this->logDir)) {
            array_map('unlink', glob($this->logDir . '/*') ?: []);
            rmdir($this->logDir);
        }
    }

    /** One exception.log entry as Magento's Monolog writes it, with a stack trace and a previous exception. */
    private static function entry(string $class, string $message, string $previous = ''): string
    {
        $json = str_replace('\\', '\\\\', $class);
        $line = '[2026-10-20T08:01:02.123456+00:00] main.CRITICAL: ' . $message . ' {"exception":"[object] (' . $json
            . '(code: 0): ' . $message . ' at /var/www/html/vendor/magento/x.php:12)' . "\n[stacktrace]\n"
            . '#0 /var/www/html/vendor/magento/y.php(34): f()' . "\n";
        if ($previous !== '') {
            $line .= '[previous exception] [object] (' . str_replace('\\', '\\\\', $previous)
                . '(code: 0): ' . $message . ' at /var/www/html/z.php:5)' . "\n";
        }
        return $line . '"} []' . "\n";
    }

    /** A system.log line; $context is appended as Monolog's JSON context. */
    private static function line(
        string $level,
        string $message,
        string $context = '[]',
        string $channel = 'main'
    ): string
    {
        return '[2026-10-20T08:01:02.123456+00:00] ' . $channel . '.' . $level . ': ' . $message . ' ' . $context
            . ' []' . "\n";
    }
}
