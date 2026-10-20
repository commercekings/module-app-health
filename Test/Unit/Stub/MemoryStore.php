<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Model\StateStore;

/** ck_app_health_state in memory, as JSON like the table; counts the statements. */
class MemoryStore extends StateStore
{
    /** @var array<string, string> key => JSON */
    public array $rows = [];
    public int $statements = 0;

    public function __construct()
    {
    }

    /** @param array<string, array<mixed>> $rows */
    public function seed(array $rows): self
    {
        foreach ($rows as $key => $value) {
            $this->rows[$key] = json_encode($value);
        }
        return $this;
    }

    /** @return array<mixed>|null */
    public function row(string $key): ?array
    {
        return isset($this->rows[$key]) ? json_decode($this->rows[$key], true) : null;
    }

    public function loadAll(): array
    {
        $this->statements++;
        return array_map(fn ($json) => json_decode($json, true), $this->rows);
    }

    public function load(array $keys): array
    {
        $this->statements++;
        return array_map(fn ($json) => json_decode($json, true), array_intersect_key($this->rows, array_flip($keys)));
    }

    public function loadPrefix(string $prefix): array
    {
        $this->statements++;
        return array_map(fn ($json) => json_decode($json, true),
            array_filter($this->rows, fn ($key) => str_starts_with($key, $prefix), ARRAY_FILTER_USE_KEY));
    }

    public function save(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->statements++;
        foreach ($rows as $key => $value) {
            $this->rows[$key] = json_encode($value, JSON_THROW_ON_ERROR);
        }
    }

    public function delete(string $key): void
    {
        $this->statements++;
        unset($this->rows[$key]);
    }

    public function deleteOldTallies(int $now): int
    {
        $this->statements++;
        $before = count($this->rows);
        $limit = 'tally:' . gmdate('YmdH', $now - self::TALLY_HOURS * 3600);
        $this->rows = array_filter($this->rows, fn ($key) => !str_starts_with($key, 'tally:') || $key >= $limit,
            ARRAY_FILTER_USE_KEY);
        return $before - count($this->rows);
    }
}
