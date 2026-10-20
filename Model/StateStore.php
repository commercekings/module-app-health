<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * The module's one table (ck_app_health_state): a few dozen JSON rows by key - "checks" (the last result per check),
 * "cursor:<name>" (what a check remembers between runs), "alerted:<website>" (what the team was last told) and
 * "tally:<YmdH>" (an hour's worst status and counts, for the daily summary; kept TALLY_HOURS).
 */
class StateStore
{
    public const TABLE = 'ck_app_health_state';
    public const TALLY_HOURS = 26;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /** @return array<string, array<mixed>> every row, by key (one query) */
    public function loadAll(): array
    {
        return $this->decode($this->db()->fetchPairs($this->db()->select()
            ->from($this->table(), ['state_key', 'value'])));
    }

    /**
     * @param string[] $keys
     * @return array<string, array<mixed>> the rows that exist, by key (one query)
     */
    public function load(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        return $this->decode($this->db()->fetchPairs($this->db()->select()
            ->from($this->table(), ['state_key', 'value'])->where('state_key IN (?)', $keys)));
    }

    /** @return array<string, array<mixed>> the rows whose key starts with $prefix (a primary key range) */
    public function loadPrefix(string $prefix): array
    {
        return $this->decode($this->db()->fetchPairs($this->db()->select()
            ->from($this->table(), ['state_key', 'value'])
            ->where('state_key LIKE ?', addcslashes($prefix, '%_\\') . '%')));
    }

    /** @param array<string, array<mixed>> $rows key => value, written in one statement */
    public function save(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $data = [];
        foreach ($rows as $key => $value) {
            $data[] = ['state_key' => (string)$key, 'value' => json_encode($value, JSON_THROW_ON_ERROR)];
        }
        $this->db()->insertOnDuplicate($this->table(), $data, ['value']);
    }

    public function delete(string $key): void
    {
        $this->db()->delete($this->table(), ['state_key = ?' => $key]);
    }

    /** Hours older than TALLY_HOURS leave the summary's window. */
    public function deleteOldTallies(int $now): int
    {
        return $this->db()->delete($this->table(), ['state_key LIKE ?' => 'tally:%',
            'state_key < ?' => 'tally:' . gmdate('YmdH', $now - self::TALLY_HOURS * 3600)]);
    }

    /**
     * @param array<string, string> $pairs
     * @return array<string, array<mixed>>
     */
    private function decode(array $pairs): array
    {
        $rows = [];
        foreach ($pairs as $key => $json) {
            $value = json_decode((string)$json, true);
            $rows[(string)$key] = is_array($value) ? $value : [];
        }
        return $rows;
    }

    private function db(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
