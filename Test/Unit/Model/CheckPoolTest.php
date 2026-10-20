<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Model\CheckPool;
use PHPUnit\Framework\TestCase;

/** The checks by their di.xml item name - the code - in di.xml order. */
class CheckPoolTest extends TestCase
{
    private function check(string $label = 'Label'): CheckInterface
    {
        $check = $this->createStub(CheckInterface::class);
        $check->method('getLabel')->willReturn($label);
        return $check;
    }

    public function testChecksByCodeInTheirOrder(): void
    {
        $pool = new CheckPool(['beta' => $this->check('Beta'), 'alpha' => $this->check('Alpha')]);
        $this->assertSame(['beta', 'alpha'], array_keys($pool->getAll()));
        $this->assertSame('Alpha', $pool->getLabel('alpha'));
        $this->assertSame('gone', $pool->getLabel('gone'), 'a check no longer installed shows its code');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidChecksAreRefused(array $checks, string $message): void
    {
        $this->expectExceptionMessage($message);
        new CheckPool(array_map(fn ($check) => $check ?? $this->check(), $checks));
    }

    public static function invalid(): array
    {
        return [
            'not a check' => [['cron' => new \stdClass()], 'item "cron" must be a ' . CheckInterface::class],
            'no code' => [[null], '"0" is not a code'],
            'invalid code' => [['Cron Check' => null], '"Cron Check" is not a code'],
            'too long' => [[str_repeat('a', 33) => null], 'at most 32 characters'],
        ];
    }
}
