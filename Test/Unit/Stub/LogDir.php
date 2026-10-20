<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\ReadFactory;

/** A temporary var/log with Magento's real directory reader. */
trait LogDir
{
    private string $logDir = '';

    private function logFilesystem(): Filesystem
    {
        $this->logDir = sys_get_temp_dir() . '/ck-app-health-' . bin2hex(random_bytes(4));
        mkdir($this->logDir);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')
            ->willReturn(new Read(new ReadFactory(new DriverPool()), new File(), $this->logDir));
        return $filesystem;
    }

    private function appendLog(string $text): void
    {
        file_put_contents($this->logDir . '/exception.log', $text, FILE_APPEND);
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
}
