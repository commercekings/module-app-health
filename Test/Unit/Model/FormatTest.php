<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function testDurations(): void
    {
        $this->assertSame(['1 min', '12 min', '119 min', '2 h', '47 h', '2 days'],
            array_map([Format::class, 'duration'], [0, 12 * 60 + 30, 119 * 60, 120 * 60, 47 * 3600 + 3599, 48 * 3600]));
    }

    public function testTopAndCounts(): void
    {
        $this->assertSame('b x5, a x2, c x1, +1 more', Format::top(['a' => 2, 'b' => 5, 'c' => 1, 'd' => 1]));
        $this->assertSame('1,437 runs, 2 errors (sitemap_generate x2)',
            Format::counts(['runs' => 1437, 'errors' => 2, 'errors:sitemap_generate' => 2]));
        $this->assertSame('3 exceptions (X x3)', Format::counts(['exceptions:X' => 3]), 'a total from its items');
        $this->assertSame('a, b and c', Format::list(['a', 'b', 'c']));
        $this->assertSame(['1 error', '2 errors', '1 critical', '1 run'], [Format::many(1, 'errors'),
            Format::many(2, 'errors'), Format::many(1, 'critical'), Format::counts(['runs' => 1])]);
        $this->assertSame('a', Format::list(['a']));
    }
}
