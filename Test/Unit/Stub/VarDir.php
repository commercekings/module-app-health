<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\WriteFactory;

/** Magento's var/ directory over a temporary folder, with Magento's own directory classes. */
trait VarDir
{
    private string $var = '';

    private function filesystem(): Filesystem
    {
        if ($this->var === '') {
            $this->var = sys_get_temp_dir() . '/ck-app-health-' . bin2hex(random_bytes(6));
            mkdir($this->var);
        }
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(fn ($code) => $code === DirectoryList::VAR_DIR
            ? new Write(new WriteFactory(new DriverPool()), new File(), $this->var, 0770)
            : throw new \LogicException('not var/'));
        return $filesystem;
    }

    protected function tearDown(): void
    {
        if ($this->var === '' || !is_dir($this->var)) {
            return;
        }
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->var,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->var);
    }
}
