<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * The module's state, in Magento's core `flag` table (no table of its own): JSON values by key - "checks" (the last
 * result per check), "cursor:<name>" (what a check remembers between runs), "alerted:<website>" (what the team was
 * last told), "tally:<YmdH>" (an hour's worst status and counts, for the daily summary; kept TALLY_HOURS), ...
 *
 * The keys are grouped into a few flag rows (GROUPS: all cursors in one, all tallies in one, ...; any other key a row
 * of its own): flag_code = PREFIX . group, flag_data = {key: value} as JSON. Reading is one query; save() and delete()
 * change only their keys in their rows - SELECT ... FOR UPDATE, one UPDATE (and an INSERT for a new row) in a
 * transaction - so two writers of different keys of one row (the cron run and the consumer) don't undo each other.
 */
class StateStore
{
    public const TABLE = 'flag';
    public const PREFIX = 'commercekings_app_health_';
    public const TALLY_HOURS = 26;
    /** A key's start => the flag row (after PREFIX) it is kept in. */
    private const GROUPS = ['cursor:' => 'cursors', 'alerted:' => 'told', 'noticed:' => 'told', 'notices:' => 'notices',
        'tally:' => 'tallies', 'status' => 'status'];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /** @return array<string, array<mixed>> every key, by key (one query) */
    public function loadAll(): array
    {
        $values = [];
        foreach ($this->rows() as $row) {
            $values += $row;
        }
        return $values;
    }

    /**
     * @param string[] $keys
     * @return array<string, array<mixed>> the keys that exist, by key (one query)
     */
    public function load(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $values = [];
        foreach ($this->rows(array_values(array_unique(array_map([$this, 'group'], $keys)))) as $row) {
            $values += array_intersect_key($row, array_flip($keys));
        }
        return $values;
    }

    /** @return array<string, array<mixed>> the keys that start with $prefix (one query) */
    public function loadPrefix(string $prefix): array
    {
        return array_filter($this->loadAll(), fn ($key) => str_starts_with((string)$key, $prefix),
            ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string, array<mixed>|null> $values key => value (null: the key removed), in one transaction */
    public function save(array $values): void
    {
        if ($values === []) {
            return;
        }
        $byGroup = [];
        foreach ($values as $key => $value) {
            $byGroup[$this->group((string)$key)][(string)$key] = $value;
        }
        $db = $this->db();
        $db->beginTransaction();
        try {
            $current = $this->rows(array_keys($byGroup), true);
            $updates = [];
            $inserts = [];
            foreach ($byGroup as $group => $changed) {
                $row = $current[$group] ?? [];
                foreach ($changed as $key => $value) {
                    if ($value === null) {
                        unset($row[$key]);
                    } else {
                        $row[$key] = $value;
                    }
                }
                $json = json_encode((object)$row, JSON_THROW_ON_ERROR);
                if (isset($current[$group])) {
                    $updates[self::PREFIX . $group] = $json;
                } else {
                    $inserts[] = ['flag_code' => self::PREFIX . $group, 'state' => 0, 'flag_data' => $json];
                }
            }
            if ($updates !== []) {
                $case = 'CASE flag_code';
                foreach ($updates as $code => $json) {
                    $case .= $db->quoteInto(' WHEN ?', $code) . $db->quoteInto(' THEN ?', $json);
                }
                $db->update($this->table(), ['flag_data' => new Expression($case . ' END')],
                    ['flag_code IN (?)' => array_keys($updates)]);
            }
            if ($inserts !== []) {
                $db->insertMultiple($this->table(), $inserts);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public function delete(string $key): void
    {
        $this->save([$key => null]);
    }

    /** Hours older than TALLY_HOURS leave the summary's window. */
    public function deleteOldTallies(int $now): int
    {
        $limit = 'tally:' . gmdate('YmdH', $now - self::TALLY_HOURS * 3600);
        $old = array_values(array_filter(array_keys($this->loadPrefix('tally:')), fn ($key) => $key < $limit));
        $this->save(array_fill_keys($old, null));
        return count($old);
    }

    /** The flag row (after PREFIX) a key is kept in. */
    private function group(string $key): string
    {
        foreach (self::GROUPS as $start => $group) {
            if (str_starts_with($key, $start)) {
                return $group;
            }
        }
        return $key;
    }

    /**
     * @param string[]|null $groups null: all of the module's rows
     * @return array<string, array<string, array<mixed>>> group => {key: value}
     */
    private function rows(?array $groups = null, bool $forUpdate = false): array
    {
        $db = $this->db();
        $select = $db->select()->from($this->table(), ['flag_code', 'flag_data'])->order('flag_id');
        if ($groups === null) {
            $select->where('flag_code LIKE ?', addcslashes(self::PREFIX, '%_\\') . '%');
        } else {
            $select->where('flag_code IN (?)', array_map(fn ($group) => self::PREFIX . $group, $groups));
        }
        if ($forUpdate) {
            $select->forUpdate(true);
        }
        $rows = [];
        foreach ($db->fetchAll($select) as $row) {
            $value = json_decode((string)$row['flag_data'], true);
            $rows[substr((string)$row['flag_code'], strlen(self::PREFIX))] = array_filter(is_array($value) ? $value
                : [], 'is_array');
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
