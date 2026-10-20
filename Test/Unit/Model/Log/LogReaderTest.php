<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Log;

use CommerceKings\AppHealth\Model\Log\LogReader;
use PHPUnit\Framework\TestCase;

class LogReaderTest extends TestCase
{
    public function testAReadIsCappedWhateverTheSetting(): void
    {
        $this->assertSame([1024 * 1024, 16 * 1024 * 1024, 16 * 1024 * 1024, 1024],
            [LogReader::cap(1024), LogReader::cap(16384), LogReader::cap(999999999), LogReader::cap(0)],
            'at most 16 MB a run (a typo in the setting reads no gigabytes); at least 1 KB');
    }
}
