<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

/**
 * The state of one cron run: read in one query when the run begins, the changed rows written in one statement when
 * it ends. Checks keep what they need between runs here ("cursor:<code>"), and share what one run reads once
 * (memo(), e.g. the new exception log lines).
 */
class RunState
{
    /** @var array<string, array<mixed>|null> null: deleted at commit() */
    private array $rows = [];

    /** @var array<string, true> */
    private array $changed = [];

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly StateStore $store
    ) {
    }

    public function begin(): void
    {
        $this->rows = $this->store->loadAll();
        $this->changed = [];
        $this->memo = [];
    }

    /** @return array<mixed>|null */
    public function get(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    /** @param array<mixed> $value */
    public function set(string $key, array $value): void
    {
        $this->rows[$key] = $value;
        $this->changed[$key] = true;
    }

    /** The key gone at commit(). */
    public function delete(string $key): void
    {
        $this->rows[$key] = null;
        $this->changed[$key] = true;
    }

    /** $compute's result, computed once per run. */
    public function memo(string $key, \Closure $compute): mixed
    {
        if (!array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $compute();
        }
        return $this->memo[$key];
    }

    public function commit(): void
    {
        $this->store->save(array_intersect_key($this->rows, $this->changed));
        $this->changed = [];
    }
}
