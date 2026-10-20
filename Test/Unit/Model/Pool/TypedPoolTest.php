<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Pool;

use CommerceKings\AppHealth\Model\Pool\TypedPool;
use PHPUnit\Framework\TestCase;

/** A di.xml pool: codes are the item names, every item of the pool's type, an error naming the pool and the key. */
class TypedPoolTest extends TestCase
{
    /** @param array<mixed> $items */
    private function pool(array $items): TypedPool
    {
        return new class ($items) extends TypedPool {
            public const CODE_LENGTH = 10;

            public function __construct(array $items)
            {
                parent::__construct($items, \Countable::class);
            }
        };
    }

    public function testItemsByCodeInOrder(): void
    {
        $a = new \SplFixedArray();
        $b = new \SplFixedArray();
        $pool = $this->pool(['zeta' => $a, 'alpha_1' => $b]);
        $this->assertSame(['zeta' => $a, 'alpha_1' => $b], $pool->getAll(), 'di.xml order, not sorted');
        $this->assertSame($b, $pool->get('alpha_1'));
        $this->assertNull($pool->get('missing'));
        $this->assertSame([true, false], [$pool->has('zeta'), $pool->has('Zeta')]);
        $this->assertSame([], $this->pool([])->getAll());
    }

    public function testAKeyThatIsNotACodeIsNamed(): void
    {
        foreach (['Upper', 'with-dash', 'eleven_char', '', 'a b'] as $key) {
            try {
                $this->pool([$key => new \SplFixedArray()]);
                $this->fail('accepted "' . $key . '"');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('"' . $key . '" is not a code', $e->getMessage());
                $this->assertStringContainsString('at most 10 characters', $e->getMessage(), 'the pool\'s own limit');
                $this->assertStringContainsString('TypedPool@anonymous', $e->getMessage(), 'names the pool');
            }
        }
        $this->expectExceptionMessage('"0" is not a code');
        $this->pool([new \SplFixedArray()]);
    }

    public function testAnItemOfAnotherTypeIsNamed(): void
    {
        $this->expectExceptionMessage('item "bad" must be a Countable, stdClass given.');
        $this->pool(['good' => new \SplFixedArray(), 'bad' => new \stdClass()]);
    }

    public function testAPoolMayTakeOtherItems(): void
    {
        $pool = new class (['src' => ['source' => 'x']]) extends TypedPool {
            public function __construct(array $items)
            {
                parent::__construct($items, 'array');
            }

            protected function accepts(mixed $item): bool
            {
                return is_array($item) && isset($item['source']);
            }

            protected function describe(): string
            {
                return 'an array with a "source"';
            }
        };
        $this->assertSame(['source' => 'x'], $pool->get('src'));
        $this->expectExceptionMessage('item "src" must be an array with a "source", int given.');
        new ($pool::class)(['src' => 1]);
    }
}
