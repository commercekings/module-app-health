<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\Amqp\ConfigPool;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\MessageQueue\Topology\ConfigInterface as TopologyConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Message queue backlogs, counts only - nothing is consumed or read: "max_messages" or more waiting in a queue (fail),
 * or a queue that kept growing for "growing_minutes" (warn). Magento's database queue: new and retry messages per
 * queue, counted from queue_message_status's (status, updated_at) index and capped at twice the limit. RabbitMQ: a
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
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Queues';
    }

    public function run(): CheckResult
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
        return match (true) {
            $full !== [] => CheckResult::fail($detail),
            $rising !== [] => CheckResult::warn($detail),
            default => CheckResult::ok(),
        };
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

    /** @return array<string, int> waiting messages by queue name in Magento's database queue, up to $cap in all */
    private function databaseSizes(int $cap): array
    {
        $db = $this->resource->getConnection();
        $status = $this->resource->getTableName('queue_message_status');
        if (!$this->hasDatabaseQueue($status)) {
            return [];
        }
        $waiting = $db->select()->from($status, ['queue_id'])->where('status IN (?)', self::WAITING)->limit($cap);
        $rows = $db->fetchPairs($db->select()
            ->from(['w' => $waiting], [])
            ->join(['q' => $this->resource->getTableName('queue')], 'q.id = w.queue_id',
                ['name' => 'q.name', 'count' => new Expression('COUNT(*)')])
            ->group('q.name'));
        return array_map('intval', $rows);
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
