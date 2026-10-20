<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\Queues;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\Amqp\Config as AmqpConfig;
use Magento\Framework\Amqp\ConfigPool;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\MessageQueue\Consumer\Config\ConsumerConfigItemInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfig;
use Magento\Framework\MessageQueue\Topology\Config\QueueConfigItemInterface;
use Magento\Framework\MessageQueue\Topology\ConfigInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueuesTest extends TestCase
{
    use Db;
    use Settings;

    private MemoryStore $store;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->clock = new Clock();
    }

    /**
     * @param array<string, int> $database waiting messages by queue in the MySQL queue
     * @param array<string, int>|null $broker messages by queue on RabbitMQ; null: no AMQP connection configured
     */
    private function check(array $database, ?array $broker = null, bool $tables = true, array $runner = [],
        ?string $lastRunner = null): CheckResult
    {
        // the database queues (id => name), then the waiting messages per queue id
        $ids = $database === [] ? [] : array_combine(range(1, count($database)), array_keys($database));
        $db = $this->db(['fetchPairs' => [$ids, array_combine(array_keys($ids), array_values($database)) ?: []],
            'fetchOne' => [$lastRunner]]);
        $db->method('isTableExists')->willReturn($tables);
        $queues = [];
        foreach (array_keys($broker ?? []) as $name) {
            $queue = $this->createStub(QueueConfigItemInterface::class);
            $queue->method('getName')->willReturn($name);
            $queue->method('getConnection')->willReturn('amqp');
            $queues[] = $queue;
        }
        $topology = $this->createStub(ConfigInterface::class);
        $topology->method('getQueues')->willReturn($queues);
        $channel = $this->createStub(AMQPChannel::class);
        $channel->method('queue_declare')->willReturnCallback(fn ($name, $passive) => $passive
            ? [$name, $broker[$name], 0] : $this->fail('never declares a queue'));
        $amqpConfig = $this->createStub(AmqpConfig::class);
        $amqpConfig->method('getChannel')->willReturn($channel);
        $pool = $this->createStub(ConfigPool::class);
        $pool->method('get')->willReturn($amqpConfig);
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('getConfigData')->willReturn($broker === null ? [] : ['amqp' => ['host' => 'rabbitmq']]);
        $deployment->method('get')->willReturn($runner);
        $consumer = $this->createStub(ConsumerConfigItemInterface::class);
        $consumer->method('getName')->willReturn('commerceKingsNotificationSend');
        $consumer->method('getQueue')->willReturn('commercekings.notification.send');
        $consumers = $this->createStub(ConsumerConfig::class);
        $consumers->method('getConsumers')->willReturn([$consumer]);
        $state = new RunState($this->store);
        $state->begin();
        $result = (new Queues($this->resource($db), $topology, $pool, $deployment,
            $this->config(['queues/max_messages' => '100', 'queues/growing_minutes' => '15']), $state, $this->clock,
            $this->createStub(LoggerInterface::class), $consumers))->run([])[0];
        $state->commit();
        $this->clock->now += 300;
        return $result;
    }

    public function testABacklogOverTheLimitFails(): void
    {
        $result = $this->check(['commercekings.notification.send' => 150, 'async.operations.all' => 2]);
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('commercekings.notification.send: 150 messages', $result->detail);
        $this->assertSame('over 200 messages', substr($this->check(['q' => 200])->detail, 3), 'counted up to the cap');
        $this->assertContains('status IN (?)', $this->wheres(), 'on the (status, updated_at) index');
        $limits = array_filter($this->calls, fn ($call) => $call[0] === 'limit');
        $this->assertSame([200], array_values(array_unique(array_column($limits, 1))), 'capped at twice the limit');
    }

    public function testTheCapIsPerQueueSoABigQueueHidesNoOther(): void
    {
        $this->calls = [];
        $result = $this->check(['big' => 200, 'commercekings.notification.send' => 150]);
        $this->assertSame('big: over 200 messages; commercekings.notification.send: 150 messages', $result->detail);
        $this->assertCount(2, array_filter($this->calls, fn ($call) => $call[0] === 'limit'), 'one cap per queue');
        $this->assertCount(2, array_filter($this->calls, fn ($call) => $call[0] === 'from' && is_array($call[1])
            && array_keys($call[1]) === ['p']), 'each capped part a derived table: "SELECT … LIMIT n UNION ALL …" is'
            . ' not SQL');
        $this->assertContains('queue_id = ?', array_map(fn ($c) => $c[1] ?? null, array_filter($this->calls,
            fn ($c) => $c[0] === 'where')));
    }

    public function testTheLargestQueuesAreNamedFirst(): void
    {
        $result = $this->check(['a' => 120, 'b' => 150, 'c' => 110, 'd' => 190, 'e' => 130]);
        $this->assertSame('d: 190 messages; b: 150 messages; e: 130 messages; +2 more', $result->detail,
            'the biggest backlogs named, the small ones counted');
        $this->assertSame(['d', 'b', 'e'], array_map(fn ($cause) => explode(':', $cause->detail)[0],
            array_values(array_filter($result->causes, fn ($cause) => $cause->code === 'queue_backlog'))));
    }

    public function testOverOnlyForACountedUpToTheCap(): void
    {
        $result = $this->check([], ['exact' => 250], false);
        $this->assertSame('exact: 250 messages', $result->detail, 'RabbitMQ counts every message: no "over"');
    }

    public function testAQueueGrowingForTheWindowWarns(): void
    {
        foreach ([3, 5, 5] as $size) {
            $this->assertSame(CheckResult::OK, $this->check(['q' => $size])->status, 'not watched long enough');
        }
        $result = $this->check(['q' => 9]);
        $this->assertSame(CheckResult::WARN, $result->status);
        $this->assertSame('q: 9 messages, growing for 15 min', $result->detail);
        $this->assertSame(CheckResult::OK, $this->check(['q' => 4])->status, 'shrinking again');
    }

    public function testRabbitMqQueuesByPassiveDeclare(): void
    {
        $result = $this->check([], ['product_action_attribute.update' => 120], false);
        $this->assertSame('product_action_attribute.update: 120 messages', $result->detail);
        $this->assertSame([], array_filter($this->calls, fn ($call) => $call[0] === 'from'
            && $call[1] === 'queue_message_status'), 'no MySQL queue tables: no query of them');
    }

    public function testCausesNameTheConsumerAndWhetherCronStartsIt(): void
    {
        $now = $this->clock->now;
        $result = $this->check(['commercekings.notification.send' => 150], null, true, [], gmdate('Y-m-d H:i:s',
            $now - 120));
        $this->assertSame(['queue_backlog', 'queue_runner'], array_map(fn ($c) => $c->code, $result->causes));
        $this->assertSame('commercekings.notification.send: 150 messages waiting, consumer'
            . ' commerceKingsNotificationSend', $result->causes[0]->detail);
        $this->assertSame('consumers_runner last ran at {t:' . ($now - 120) . '}', $result->causes[1]->detail);
        $off = $this->check(['commercekings.notification.send' => 150], null, true, ['cron_run' => false,
            'consumers' => ['async.operations.all']]);
        $this->assertSame(['queue_backlog', 'queue_consumer_not_started', 'queue_consumers_off'],
            array_map(fn ($c) => $c->code, $off->causes));
        $none = $this->check(['commercekings.notification.send' => 150]);
        $this->assertSame('queue_runner_not_running', $none->causes[1]->code);
    }
}
