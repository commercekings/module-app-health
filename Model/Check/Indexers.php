<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\Cause;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ConfigInterface as IndexerConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Indexers invalid, or stuck "working", for over "minutes" (fail), from indexer_state (one row per indexer); its
 * "updated" is the time of the last status change. Only indexers Magento's configuration still has count. Causes:
 * which indexer, since when, its mode (mview_state) and when cron's reindex jobs last ran.
 */
class Indexers implements CheckInterface
{
    public const CODE = 'indexers';
    /** Cron's jobs that reindex: invalid indexers, and those updated by schedule. */
    private const JOBS = ['indexer_reindex_all_invalid', 'indexer_update_all_views'];

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

    public function getHint(): string
    {
        return (string)__('bin/magento indexer:status; is the indexer cron (indexer_reindex_all_invalid) running?');
    }

    public function getLabel(): string
    {
        return 'Indexers';
    }

    public function run(): CheckResult
    {
        $indexers = $this->indexerConfig->getIndexers();
        $ids = array_map('strval', array_keys($indexers));
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
                $problems[(string)$row['indexer_id']] = [(string)$row['status'], $since];
            }
        }
        if ($problems === []) {
            return CheckResult::ok();
        }
        ksort($problems);
        $parts = [];
        foreach ($problems as $id => [$status, $since]) {
            $parts[] = sprintf('%s %s for %s', $id, $status, $since ? Format::duration($now - $since) : 'long');
        }
        $more = count($parts) - 3;
        $invalid = array_filter($problems, fn ($problem) => $problem[0] === 'invalid');
        return CheckResult::fail(implode(', ', array_slice($parts, 0, 3)) . ($more > 0 ? ', +' . $more . ' more' : ''),
            [], $invalid !== [] ? 'Indexer invalid' : 'Indexer stuck')
            ->withCauses($this->causes(array_slice($problems, 0, 3, true), $indexers, $now));
    }

    /**
     * Which indexer, since when, its mode, and whether cron's reindex jobs ran - read only when one fails.
     *
     * @param array<string, array{0: string, 1: int}> $problems
     * @param array<string, mixed> $indexers Magento's indexer configuration
     * @return Cause[]
     */
    private function causes(array $problems, array $indexers, int $now): array
    {
        $db = $this->resource->getConnection();
        $views = [];
        foreach (array_keys($problems) as $id) {
            $view = (string)($indexers[$id]['view_id'] ?? '');
            if ($view !== '') {
                $views[$view] = $id;
            }
        }
        $scheduled = $views === [] ? [] : $db->fetchPairs($db->select()
            ->from($this->resource->getTableName('mview_state'), ['view_id', 'mode'])
            ->where('view_id IN (?)', array_keys($views)));
        $modes = [];
        foreach ($views as $view => $id) {
            $modes[$id] = ($scheduled[$view] ?? '') === 'enabled' ? 'update by schedule' : 'update on save';
        }
        $causes = [];
        foreach ($problems as $id => [$status, $since]) {
            $when = $since ? ' since ' . Format::at($since) : '';
            $causes[] = $status === 'working'
                ? new Cause('indexer_stuck', sprintf('%s still working%s (%s): a reindex may have crashed', $id,
                    $when, $modes[$id] ?? 'update on save'), (string)__('bin/magento indexer:reset %1, then'
                    . ' bin/magento indexer:reindex %1 and read its output.', $id))
                : new Cause('indexer_invalid', sprintf('%s invalid%s (%s)', $id, $when, $modes[$id]
                    ?? 'update on save'), (string)__('bin/magento indexer:reindex %1; if it stays invalid, read its'
                    . ' output.', $id));
        }
        $ran = $db->fetchPairs($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['job_code', 'last' => 'MAX(finished_at)'])
            ->where('job_code IN (?)', self::JOBS)
            ->where('status = ?', 'success')
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 86400))
            ->group('job_code'));
        foreach (self::JOBS as $job) {
            $last = isset($ran[$job]) ? (int)strtotime($ran[$job] . ' UTC') : 0;
            $causes[] = new Cause('indexer_cron', $last > 0 ? sprintf('%s last ran at %s', $job, Format::at($last))
                : sprintf('%s has not run in 24 h', $job), $last > 0 ? '' : (string)__('Cron reindexes:'
                . ' see the Cron check, and that the index cron group runs.'));
        }
        return $causes;
    }
}
