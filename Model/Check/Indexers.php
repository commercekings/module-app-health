<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Indexers invalid, or stuck "working", for over "minutes" (fail), from indexer_state (one row per indexer); its
 * "updated" is the time of the last status change. Only indexers Magento's configuration still has count.
 */
class Indexers implements CheckInterface
{
    public const CODE = 'indexers';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly IndexerConfig $indexerConfig,
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Indexers';
    }

    public function run(): CheckResult
    {
        $ids = array_map('strval', array_keys($this->indexerConfig->getIndexers()));
        if ($ids === []) {
            return CheckResult::ok();
        }
        $now = $this->dateTime->gmtTimestamp();
        $limit = $now - $this->config->getInt(self::CODE, 'minutes', 60) * 60;
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()
            ->from($this->resource->getTableName('indexer_state'), ['indexer_id', 'status', 'updated'])
            ->where('indexer_id IN (?)', $ids)
            ->where('status IN (?)', ['invalid', 'working']));
        $problems = [];
        foreach ($rows as $row) {
            $since = $row['updated'] ? (int)strtotime($row['updated'] . ' UTC') : 0;
            if ($since <= $limit) {
                $problems[] = sprintf('%s %s', $row['indexer_id'], $row['status'] === 'working'
                    ? 'working for ' . ($since ? Format::duration($now - $since) : 'long')
                    : 'invalid for ' . ($since ? Format::duration($now - $since) : 'long'));
            }
        }
        if ($problems === []) {
            return CheckResult::ok();
        }
        sort($problems);
        $more = count($problems) - 3;
        return CheckResult::fail(implode(', ', array_slice($problems, 0, 3))
            . ($more > 0 ? ', +' . $more . ' more' : ''));
    }
}
