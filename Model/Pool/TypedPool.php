<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Pool;

/**
 * A named set of implementations from a di.xml array argument (code => item), checked once: every item is what the
 * pool takes (by default an instance of the type it names) and every key is a code - [a-z0-9_], at most
 * static::CODE_LENGTH characters - the item's one name, used for its state, its settings and references to it. Keys
 * are unique by construction: a later module's item with the same key replaces the earlier one, as di.xml merges
 * them. Items stay in di.xml order.
 *
 * A pool extends it with its own constructor and argument name:
 *
 *     public function __construct(array $checks = []) { parent::__construct($checks, CheckInterface::class); }
 *
 * Internal to App health (not @api).
 * @template T
 */
abstract class TypedPool
{
    public const CODE_LENGTH = 64;

    /** @var array<string, T> */
    private readonly array $items;

    /**
     * @param array<mixed> $items the di.xml argument
     * @param string $type what an item must be: a class or interface name (see accepts())
     * @throws \InvalidArgumentException naming the pool and the item's key
     */
    public function __construct(array $items, private readonly string $type)
    {
        foreach ($items as $code => $item) {
            if (!is_string($code) || !preg_match('/^[a-z0-9_]{1,' . static::CODE_LENGTH . '}$/D', $code)) {
                throw new \InvalidArgumentException(sprintf('%s: "%s" is not a code (a-z, 0-9 and _, at most %d'
                    . ' characters); the di.xml item name is the code.', static::class, $code, static::CODE_LENGTH));
            }
            if (!$this->accepts($item)) {
                throw new \InvalidArgumentException(sprintf('%s: item "%s" must be %s, %s given.', static::class,
                    $code, $this->describe(), get_debug_type($item)));
            }
        }
        $this->items = $items;
    }

    /** @return array<string, T> by code, in di.xml order */
    public function getAll(): array
    {
        return $this->items;
    }

    /** @return T|null */
    public function get(string $code): mixed
    {
        return $this->items[$code] ?? null;
    }

    public function has(string $code): bool
    {
        return isset($this->items[$code]);
    }

    /** Whether the pool takes this item; an instance of the pool's type unless a pool takes more (e.g. arrays). */
    protected function accepts(mixed $item): bool
    {
        return $item instanceof $this->type;
    }

    /** What an item must be, for the error. */
    protected function describe(): string
    {
        return 'a ' . $this->type;
    }
}
