<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Api\Data;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase
{
    public function testStatusesAndTheirRanks(): void
    {
        $this->assertSame([0, 1, 2], [CheckResult::rank(CheckResult::ok()->status),
            CheckResult::rank(CheckResult::warn('w')->status), CheckResult::rank(CheckResult::fail('f')->status)]);
        $this->assertSame([CheckResult::OK, CheckResult::WARN, CheckResult::FAIL, CheckResult::FAIL],
            [CheckResult::fromRank(0), CheckResult::fromRank(1), CheckResult::fromRank(2), CheckResult::fromRank(7)]);
        $this->expectException(\InvalidArgumentException::class);
        new CheckResult('broken');
    }

    public function testTheDetailIsOneShortLineWithoutAddresses(): void
    {
        $result = CheckResult::fail("cart of jane.doe@example.com\nfailed   twice");
        $this->assertSame('cart of [address] failed twice', $result->detail);
        $long = CheckResult::fail(str_repeat('x', 500))->detail;
        $this->assertSame(CheckResult::DETAIL_LENGTH, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }

    public function testCountsAreNamesWithWholeNumbers(): void
    {
        $counts = ['runs' => 3, 'errors:sitemap_generate' => 1, 'exceptions:Magento\\Framework\\Exception\\X' => 2,
            'messages:product_action_attribute.update' => 0, 'mail errors' => 0];
        $this->assertSame($counts, CheckResult::ok('', $counts)->counts);
        foreach ([['Runs' => 1], ['errors:jane doe' => 1], ['errors:a@b.c' => 1], ['runs' => -1], ['runs' => '3'],
            [5 => 1]] as $bad) {
            try {
                CheckResult::ok('', $bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
