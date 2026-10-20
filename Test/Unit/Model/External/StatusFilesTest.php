<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\External;

use CommerceKings\AppHealth\Model\External\StatusFiles;
use CommerceKings\AppHealth\Test\Unit\Stub\VarDir;
use PHPUnit\Framework\TestCase;

/** var/app-health: created with status.json.dist when missing; status-<source>.json files only, bounded. */
class StatusFilesTest extends TestCase
{
    use VarDir;

    private function files(): StatusFiles
    {
        return new StatusFiles($this->filesystem());
    }

    public function testTheFolderAndTheExampleAreCreatedOnTheFirstRun(): void
    {
        $this->assertSame(['files' => [], 'more' => 0], $this->files()->read());
        $this->assertDirectoryExists($this->var . '/app-health');
        $dist = (string)file_get_contents($this->var . '/app-health/status.json.dist');
        $this->assertSame(['report'], array_keys(json_decode($dist, true)), 'the format, to copy from');
        file_put_contents($this->var . '/app-health/status.json.dist', 'edited');
        $this->files()->read();
        $this->assertSame('edited', file_get_contents($this->var . '/app-health/status.json.dist'), 'never replaced');
    }

    public function testOnlyStatusFilesAreReadAndTheBigOnesNot(): void
    {
        $this->files()->read();
        $dir = $this->var . '/app-health/';
        file_put_contents($dir . 'status-host-1.json', '{"a":1}');
        file_put_contents($dir . 'status-host-1.json.tmp.123', 'half written');   // the script's temporary file
        file_put_contents($dir . '.status-host-2.json.XXXX', 'half written');
        file_put_contents($dir . 'status-bad name.json', '{}');
        file_put_contents($dir . 'status-big.json', str_repeat(' ', StatusFiles::MAX_BYTES + 1));
        file_put_contents($dir . 'status-edge.json', str_repeat(' ', StatusFiles::MAX_BYTES));
        $read = $this->files()->read();
        $this->assertSame(['status-big.json' => ['source' => 'big', 'json' => null],
            'status-edge.json' => ['source' => 'edge', 'json' => str_repeat(' ', StatusFiles::MAX_BYTES)],
            'status-host-1.json' => ['source' => 'host-1', 'json' => '{"a":1}']], $read['files']);
    }

    public function testAtMostTwentyFiles(): void
    {
        $this->files()->read();
        foreach (range(1, StatusFiles::MAX_FILES + 3) as $i) {
            file_put_contents(sprintf('%s/app-health/status-h%02d.json', $this->var, $i), '{}');
        }
        $read = $this->files()->read();
        $this->assertCount(StatusFiles::MAX_FILES, $read['files']);
        $this->assertSame(3, $read['more']);
        $this->assertArrayHasKey('status-h01.json', $read['files'], 'by name');
    }
}
