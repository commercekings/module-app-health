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
    private function check(array $database, ?array $broker = null, bool $tables = true): CheckResult
    {
        $db = $this->db(['fetchPairs' => [$database]]);
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
        $state = new RunState($this->store);
        $state->begin();
        $result = (new Queues($this->resource($db), $topology, $pool, $deployment,
            $this->config(['queues/max_messages' => '100', 'queues/growing_minutes' => '15']), $state, $this->clock,
            $this->createStub(LoggerInterface::class)))->run();
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
        $this->assertSame(200, array_values($limits)[0][1], 'capped at twice the limit');
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
        $this->assertSame([], array_filter($this->calls, fn ($call) => $call[0] === 'from'),
            'no MySQL queue tables: no query');
    }
}
