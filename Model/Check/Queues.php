<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\Cause;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\Amqp\ConfigPool;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Message queue backlogs, counts only - nothing is consumed or read: "max_messages" or more waiting in a queue (fail),
 * or a queue that kept growing for "growing_minutes" (warn). Magento's database queue: new and retry messages per
 * queue, counted from queue_message_status and capped at twice the limit per queue. RabbitMQ: a
 * passive declare per queue of the topology on an AMQP connection (the broker's message count). Off by default: it
 * opens a broker connection on every run.
 */
class Queues implements CheckInterface
{
    public const CODE = 'queues';
    /** queue_message_status: MESSAGE_STATUS_NEW and MESSAGE_STATUS_RETRY_REQUIRED of Magento_MysqlMq */
    private const WAITING = [2, 5];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly TopologyConfig $topology,
        private readonly ConfigPool $amqp,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly ConsumerConfig $consumerConfig
    ) {
    }

        /** What to do, for a problem without a hint of its own. */
    private function hint(): string
    {
        return (string)__('Are the consumers running (cron_consumers_runner or supervisor)?'
            . ' bin/magento queue:consumers:list');
    }

    public function getLabel(): string
    {
        return 'Queue';
    }

    public function run(array $websiteIds): array
    {
        return [self::SHOP => $this->check()->withHint($this->hint())];
    }

    private function check(): CheckResult
    {
        $now = $this->dateTime->gmtTimestamp();
        $max = $this->config->getInt(self::CODE, 'max_messages', 1000);
        $growing = $this->config->getInt(self::CODE, 'growing_minutes', 15) * 60;
        $sizes = $this->databaseSizes($max * 2) + $this->brokerSizes();
        $samples = (array)($this->state->get('cursor:' . self::CODE)['samples'] ?? []);
        $kept = [];
        foreach ($sizes as $queue => $size) {
            $history = array_values(array_filter((array)($samples[$queue] ?? []),
                fn ($sample) => is_array($sample) && (int)$sample[0] >= $now - $growing - 600));
            $history[] = [$now, $size];
            $kept[$queue] = $history;
        }
        $this->state->set('cursor:' . self::CODE, ['samples' => $kept]);
        $full = [];
        $rising = [];
        foreach ($kept as $queue => $history) {
            $size = (int)end($history)[1];
            if ($size >= $max) {
                $full[$queue] = $size;
            } elseif ($this->grew($history, $now - $growing)) {
                $rising[$queue] = $size;
            }
        }
        $parts = [];
        foreach ($full + $rising as $queue => $size) {
            $parts[] = sprintf('%s: %s%s messages%s', $queue, $size >= $max * 2 ? 'over ' : '',
                Format::number($size), isset($rising[$queue]) ? ', growing for ' . Format::duration($growing) : '');
        }
        $detail = implode('; ', array_slice($parts, 0, 3))
            . (count($parts) > 3 ? '; +' . (count($parts) - 3) . ' more' : '');
        if ($full === [] && $rising === []) {
            return CheckResult::ok();
        }
        $result = $full !== [] ? CheckResult::fail($detail, [], 'Queue backlog')
            : CheckResult::warn($detail, [], 'Queue growing');
        return $result->withCauses($this->causes(array_slice($full + $rising, 0, 3, true), $now));
    }

    /**
     * Which queue and its consumer, and whether cron starts the consumers (env.php cron_consumers_runner) and ran
     * consumers_runner lately - read only when a queue backs up.
     *
     * @param array<string, int> $queues name => size
     * @return Cause[]
     */
    private function causes(array $queues, int $now): array
    {
        $consumers = [];
        foreach ($this->consumerConfig->getConsumers() as $consumer) {
            $consumers[(string)$consumer->getQueue()][] = (string)$consumer->getName();
        }
        $runner = (array)$this->deploymentConfig->get('cron_consumers_runner', []);
        $allowed = array_map('strval', (array)($runner['consumers'] ?? []));
        $causes = [];
        foreach ($queues as $queue => $size) {
            $names = $consumers[$queue] ?? [];
            $causes[] = new Cause('queue_backlog', sprintf('%s: %s messages waiting, consumer %s', $queue,
                Format::number($size), $names !== [] ? implode(', ', $names) : 'none configured'));
            $notAllowed = $allowed !== [] ? array_diff($names, $allowed) : [];
            if ($notAllowed !== []) {
                $causes[] = new Cause('queue_consumer_not_started', sprintf('%s is not in cron_consumers_runner'
                    . ' consumers (env.php)', implode(', ', $notAllowed)), (string)__('Add it to'
                    . ' cron_consumers_runner > consumers in app/etc/env.php, or run it under supervisor.'));
            }
        }
        if (array_key_exists('cron_run', $runner) && !$runner['cron_run']) {
            $causes[] = new Cause('queue_consumers_off', 'cron does not start the consumers'
                . ' (cron_consumers_runner > cron_run is off in env.php)', (string)__('Run the consumers under'
                . ' supervisor (bin/magento queue:consumers:start <name>), or turn cron_run on.'));
            return $causes;
        }
        $db = $this->resource->getConnection();
        $last = $db->fetchOne($db->select()
            ->from($this->resource->getTableName('cron_schedule'), ['last' => 'MAX(finished_at)'])
            ->where('job_code IN (?)', ['consumers_runner'])
            ->where('status = ?', 'success')
            ->where('scheduled_at >= ?', gmdate('Y-m-d H:i:s', $now - 86400)));
        $causes[] = $last ? new Cause('queue_runner', 'consumers_runner last ran at '
            . Format::at((int)strtotime($last . ' UTC')), (string)__('Is the consumer process running and finishing?'
            . ' bin/magento queue:consumers:start <name> by hand shows its errors.'))
            : new Cause('queue_runner_not_running', 'consumers_runner has not run in 24 h',
                (string)__('Cron starts the consumers: see the Cron check.'));
        return $causes;
    }

    /** Never smaller from one run to the next since $from, at least 2 runs, and larger at the end. */
    private function grew(array $history, int $from): bool
    {
        $window = array_values(array_filter($history, fn ($sample) => (int)$sample[0] >= $from - 60));
        if (count($window) < 2 || (int)$window[0][0] > $from + 60) {
            return false; // not watched for the whole window yet
        }
        for ($i = 1, $n = count($window); $i < $n; $i++) {
            if ((int)$window[$i][1] < (int)$window[$i - 1][1]) {
                return false;
            }
        }
        return (int)$window[count($window) - 1][1] > (int)$window[0][1];
    }

    /**
     * Waiting messages by queue name in Magento's database queue, up to $cap per queue (a big queue hides no other):
     * one statement, a capped part per queue.
     *
     * @return array<string, int>
     */
    private function databaseSizes(int $cap): array
    {
        $db = $this->resource->getConnection();
        $status = $this->resource->getTableName('queue_message_status');
        if (!$this->hasDatabaseQueue($status)) {
            return [];
        }
        $names = $db->fetchPairs($db->select()->from($this->resource->getTableName('queue'), ['id', 'name']));
        if ($names === []) {
            return [];
        }
        $parts = [];
        foreach (array_keys($names) as $id) {
            // a derived table each: a LIMIT inside a UNION needs it
            $parts[] = $db->select()->from(['p' => $db->select()->from($status, ['queue_id'])
                ->where('queue_id = ?', (int)$id)->where('status IN (?)', self::WAITING)->limit($cap)], ['queue_id']);
        }
        $counts = $db->fetchPairs($db->select()
            ->from(['w' => $db->select()->union($parts, \Magento\Framework\DB\Select::SQL_UNION_ALL)],
                ['queue_id', 'count' => new Expression('COUNT(*)')])
            ->group('queue_id'));
        $sizes = [];
        foreach ($counts as $id => $count) {
            if (isset($names[$id])) {
                $sizes[(string)$names[$id]] = (int)$count;
            }
        }
        return $sizes;
    }

    /** Whether Magento_MysqlMq's tables exist, checked once a day. */
    private function hasDatabaseQueue(string $table): bool
    {
        $known = $this->state->get('cursor:' . self::CODE . '_db');
        if ($known !== null && (int)($known['checked'] ?? 0) > $this->dateTime->gmtTimestamp() - 86400) {
            return !empty($known['exists']);
        }
        $exists = $this->resource->getConnection()->isTableExists($table);
        $this->state->set('cursor:' . self::CODE . '_db', ['exists' => $exists,
            'checked' => $this->dateTime->gmtTimestamp()]);
        return $exists;
    }

    /** @return array<string, int> messages by queue name on the configured AMQP connections */
    private function brokerSizes(): array
    {
        $queueConfig = (array)$this->deploymentConfig->getConfigData('queue');
        $sizes = [];
        foreach ($this->topology->getQueues() as $queue) {
            $connection = (string)$queue->getConnection();
            $configured = $connection === 'amqp' ? !empty($queueConfig['amqp']['host'])
                : !empty($queueConfig['connections'][$connection]['host']);
            if ($connection === 'db' || !$configured) {
                continue;
            }
            try {
                $declared = $this->amqp->get($connection)->getChannel()->queue_declare($queue->getName(), true);
            } catch (\Throwable $e) {
                // a missing queue closes the channel: stop for this run
                $this->logger->warning(sprintf('CommerceKings_AppHealth: queue sizes not read from "%s" (%s)',
                    $connection, get_class($e)));
                break;
            }
            if (is_array($declared) && isset($declared[1])) {
                $sizes[(string)$queue->getName()] = (int)$declared[1];
            }
        }
        return $sizes;
    }
}
