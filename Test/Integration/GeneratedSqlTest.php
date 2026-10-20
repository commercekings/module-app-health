<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Integration;

use CommerceKings\AppHealth\Model\Check\EmailFailing;
use CommerceKings\AppHealth\Model\Check\Queues;
use CommerceKings\AppHealth\Model\Check\SalesEmails;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The statements the checks build (UNIONs of capped parts, derived tables) run on MySQL/MariaDB - the unit tests'
 * adapter stub runs no SQL. On copies of the tables (ck_it_<name>, created LIKE the shop's and dropped again; real
 * tables, as MySQL can't open a temporary table twice in one statement), so no shop data is read or changed.
 */
class GeneratedSqlTest extends TestCase
{
    use Settings;

    private const TABLES = ['queue', 'queue_message_status', 'sales_order', 'sales_invoice', 'sales_shipment',
        'sales_creditmemo', 'ck_notification_delivery_state'];

    private AdapterInterface $db;
    private ResourceConnection $resource;
    private int $now;

    private function table(string $name): string
    {
        return 'ck_it_' . $name;
    }

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        require_once $root . '/app/bootstrap.php';
        $real = Bootstrap::create(BP, $_SERVER)->getObjectManager()->get(ResourceConnection::class);
        $this->db = $real->getConnection();
        foreach (self::TABLES as $name) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->table($name));
            $this->db->query('CREATE TABLE ' . $this->table($name) . ' LIKE '
                . $this->db->quoteIdentifier($real->getTableName($name)));
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->db);
        $resource->method('getTableName')->willReturnCallback(fn ($name) => $this->table((string)$name));
        $this->resource = $resource;
        $this->now = time();
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $name) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->table($name));
        }
    }

    /** A private method of $object, called. */
    private function call(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    private function at(int $secondsAgo): string
    {
        return gmdate('Y-m-d H:i:s', $this->now - $secondsAgo);
    }

    public function testQueuesCountsEachQueueUpToItsCap(): void
    {
        $this->db->insertMultiple($this->table('queue'), [['id' => 1, 'name' => 'busy'], ['id' => 2, 'name' => 'quiet'],
            ['id' => 3, 'name' => 'empty']]);
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['queue_id' => 1, 'message_id' => $i, 'updated_at' => $this->at(60), 'status' => 2,
                'number_of_trials' => 0];
        }
        $rows[] = ['queue_id' => 2, 'message_id' => 100, 'updated_at' => $this->at(60), 'status' => 5,
            'number_of_trials' => 1];
        $rows[] = ['queue_id' => 2, 'message_id' => 101, 'updated_at' => $this->at(60), 'status' => 4,
            'number_of_trials' => 1]; // complete: not waiting
        $this->db->insertMultiple($this->table('queue_message_status'), $rows);
        $state = new RunState((new MemoryStore())->seed(['cursor:queues_db' => ['exists' => true,
            'checked' => $this->now]]));
        $state->begin();
        $queues = (new \ReflectionClass(Queues::class))->newInstanceWithoutConstructor();
        $properties = ['resource' => $this->resource, 'state' => $state, 'dateTime' => new Clock($this->now)];
        foreach ($properties as $name => $value) {
            (new \ReflectionProperty(Queues::class, $name))->setValue($queues, $value);
        }
        $sizes = $this->call($queues, 'databaseSizes', 20);
        ksort($sizes);
        $this->assertSame(['busy' => 20, 'quiet' => 1], $sizes, 'capped per queue; complete and empty not counted');
    }

    public function testSalesEmailsCountsTheUnsentInOneUnion(): void
    {
        $order = fn (int $id, string $state, int $ago) => ['entity_id' => $id, 'state' => $state, 'status' => 'x',
            'store_id' => 1, 'created_at' => $this->at($ago), 'send_email' => 1, 'email_sent' => null];
        $this->db->insertMultiple($this->table('sales_order'), [$order(1, 'new', 3600), $order(2, 'processing', 7200),
            $order(3, 'canceled', 3600), $order(4, 'closed', 3600), $order(5, 'new', 60), $order(6, 'new', 90000)]);
        $check = new SalesEmails($this->resource, $this->createStub(\Magento\Store\Model\StoreManagerInterface::class),
            $this->config(), new Clock($this->now),
            $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class));
        $rows = $this->call($check, 'unsent', 30);
        $this->assertCount(1, $rows, 'orders only: the other tables are empty');
        $this->assertSame(['order', 1, 2], [$rows[0]['entity'], (int)$rows[0]['store_id'], (int)$rows[0]['count']],
            'not cancelled or closed, older than 30 min, within 24 h');
        $this->assertSame($this->at(7200), $rows[0]['oldest']);
    }

    public function testEmailFailingSeesASendSinceInOneUnion(): void
    {
        $deliveryState = $this->createStub(\CommerceKings\Notification\Api\DeliveryStateInterface::class);
        $deliveryState->method('getTableName')->willReturn($this->table('ck_notification_delivery_state'));
        $check = (new \ReflectionClass(EmailFailing::class))->newInstanceWithoutConstructor();
        foreach (['resource' => $this->resource, 'deliveryState' => $deliveryState] as $name => $value) {
            (new \ReflectionProperty(EmailFailing::class, $name))->setValue($check, $value);
        }
        $this->assertFalse($this->call($check, 'sentSince', $this->now - 600), 'nothing sent');
        $this->db->insert($this->table('sales_invoice'), ['entity_id' => 1, 'store_id' => 1, 'order_id' => 1,
            'created_at' => $this->at(900), 'updated_at' => $this->at(60), 'email_sent' => 1]);
        $this->assertTrue($this->call($check, 'sentSince', $this->now - 600), 'an invoice emailed since');
    }
}
