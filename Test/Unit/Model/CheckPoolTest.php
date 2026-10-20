<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Model\CheckPool;
use PHPUnit\Framework\TestCase;

class CheckPoolTest extends TestCase
{
    private function check(string $code, string $label = 'Label'): CheckInterface
    {
        $check = $this->createStub(CheckInterface::class);
        $check->method('getCode')->willReturn($code);
        $check->method('getLabel')->willReturn($label);
        return $check;
    }

    public function testChecksByCodeInTheirOrder(): void
    {
        $pool = new CheckPool(['b' => $this->check('beta', 'Beta'), 'a' => $this->check('alpha', 'Alpha')]);
        $this->assertSame(['beta', 'alpha'], array_keys($pool->getAll()));
        $this->assertSame('Alpha', $pool->getLabel('alpha'));
        $this->assertSame('gone', $pool->getLabel('gone'), 'a check no longer installed shows its code');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidChecksAreRefused(array $checks): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckPool(array_map(fn ($check) => is_string($check) ? $this->check($check) : $check, $checks));
    }

    public static function invalid(): array
    {
        return [
            'not a check' => [[new \stdClass()]],
            'invalid code' => [['Cron Check']],
            'too long' => [[str_repeat('a', 33)]],
            'twice' => [['cron', 'cron']],
        ];
    }
}
