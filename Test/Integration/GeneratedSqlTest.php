<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Integration;

use CommerceKings\AppHealth\Model\Check\EmailFailing;
use CommerceKings\AppHealth\Model\Check\Queues;
use CommerceKings\AppHealth\Model\Check\SalesEmails;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\StateStore;
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
        'sales_creditmemo', 'ck_notification_delivery_state', 'flag'];

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
        $real = Bootstrap::create((string)\constant('BP'), $_SERVER)->getObjectManager()
            ->get(ResourceConnection::class);
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

    public function testTeamMessageSeesAnAppHealthMessageFailingToGoOut(): void
    {
        $deliveryState = $this->createStub(\CommerceKings\Notification\Api\DeliveryStateInterface::class);
        $deliveryState->method('getTableName')->willReturn($this->table('ck_notification_delivery_state'));
        $targets = $this->createStub(\CommerceKings\AppHealth\Model\Targets::class);
        $targets->method('forWebsite')->willReturnCallback(fn (int $id) => $id === 0
            ? new \CommerceKings\AppHealth\Model\Target(0, 1, 'slack', 'C0', 'Shop', 'UTC') : null);
        $teamMessage = new \CommerceKings\AppHealth\Model\Notification\TeamMessage($this->resource, $deliveryState,
            $this->createStub(\CommerceKings\Notification\Api\QueueInterface::class),
            $this->createStub(\CommerceKings\Notification\Api\SenderInterface::class),
            $targets,
            $this->createStub(\Psr\Log\LoggerInterface::class),
            $this->createStub(\CommerceKings\AppHealth\Model\SiteDomain::class));
        $row = ['reference_id' => 0, 'subject_key' => '', 'delivery_version' => 1];
        $this->db->insertMultiple($this->table('ck_notification_delivery_state'), [
            ['type' => 'app_health_status', 'failed_sends' => 0] + $row,
            ['type' => 'cart_reminder', 'failed_sends' => 3] + $row]);
        $this->assertFalse($teamMessage->isFailing(), 'another type\'s failures are not App health\'s');
        $this->db->insertMultiple($this->table('ck_notification_delivery_state'), [
            ['type' => 'app_health_summary', 'failed_sends' => 2, 'updated_at' => gmdate('Y-m-d H:i:s',
                $this->now - 2 * 86400)] + $row,
            ['type' => 'app_health_alert', 'failed_sends' => 2, 'updated_at' => gmdate('Y-m-d H:i:s', $this->now),
                'reference_id' => 7] + $row]);
        $this->assertFalse($teamMessage->isFailing(), 'a row left two days ago (summaries off), a website gone:'
            . ' obsolete');
        $this->db->insert($this->table('ck_notification_delivery_state'), ['type' => 'app_health_alert',
            'reference_id' => 0, 'subject_key' => '', 'delivery_version' => 1, 'failed_sends' => 0,
            'skip_until' => gmdate('Y-m-d H:i:s', $this->now + 600)]);
        $this->assertTrue($teamMessage->isFailing(), 'a retry waiting');
    }

    public function testOneWorkerPostsAMessageEvenWhenAnotherReadsItMidSend(): void
    {
        $table = $this->table('ck_notification_delivery_state');
        $key = ['type = ?' => 'app_health_alert', 'reference_id = ?' => 0, 'subject_key = ?' => ''];
        $deliveryState = $this->createStub(\CommerceKings\Notification\Api\DeliveryStateInterface::class);
        $deliveryState->method('getTableName')->willReturn($table);
        $deliveryState->method('keyCondition')->willReturn($key);
        $deliveryState->method('load')->willReturnCallback(fn () => $this->db->fetchRow($this->db->select()
            ->from($table)->where('type = ?', 'app_health_alert')) ?: null);
        $targets = $this->createStub(\CommerceKings\AppHealth\Model\Targets::class);
        $targets->method('forWebsite')->willReturn(new \CommerceKings\AppHealth\Model\Target(0, 1, 'slack', 'C0',
            'Shop', 'UTC'));
        $domain = $this->createStub(\CommerceKings\AppHealth\Model\SiteDomain::class);
        $domain->method('matches')->willReturn(true);
        $sender = $this->createStub(\CommerceKings\Notification\Api\SenderInterface::class);
        $teamMessage = new \CommerceKings\AppHealth\Model\Notification\TeamMessage($this->resource, $deliveryState,
            $this->createStub(\CommerceKings\Notification\Api\QueueInterface::class), $sender, $targets,
            $this->createStub(\Psr\Log\LoggerInterface::class), $domain);
        $posts = 0;
        $told = 0;
        $compose = function () use (&$told) {
            return ['Problem: Cron stopped', function () use (&$told) {
                $told++;
            }];
        };
        $second = null;
        $sender->method('send')->willReturnCallback(function () use (&$posts, &$second, $teamMessage, $compose) {
            $posts++;
            // worker B gets a copy of the message while A is sending: it reads A's claim
            $second ??= $teamMessage->deliver('app_health_alert', '0', $compose);
            return \CommerceKings\Notification\Api\SenderInterface::SENT;
        });
        $this->db->insert($table, ['type' => 'app_health_alert', 'reference_id' => 0, 'subject_key' => '',
            'delivery_version' => 4, 'failed_sends' => 0, 'queued_at' => gmdate('Y-m-d H:i:s', $this->now)]);
        $this->assertTrue($teamMessage->deliver('app_health_alert', '0', $compose));
        $this->assertSame([false, 1, 1], [$second, $posts, $told], 'B sends nothing: one post, told once');
        $row = $deliveryState->load('app_health_alert', 0);
        $this->assertSame([6, null], [(int)$row['delivery_version'], $row['queued_at']], 'the claim ended');

        // a worker that claimed and stopped (maybe after Slack took it): within the lease nothing; after, told
        $this->db->update($table, ['delivery_version' => 7, 'queued_at' => gmdate('Y-m-d H:i:s', $this->now),
            'updated_at' => gmdate('Y-m-d H:i:s', $this->now)], $key);
        $this->assertFalse($teamMessage->deliver('app_health_alert', '0', $compose), 'another worker may be sending');
        $this->db->update($table, ['updated_at' => gmdate('Y-m-d H:i:s', $this->now - 1200)], $key);
        $this->assertTrue($teamMessage->deliver('app_health_alert', '0', $compose));
        $this->assertSame([1, 2], [$posts, $told], 'maybe sent: recorded as told, not posted again');
        $row = $deliveryState->load('app_health_alert', 0);
        $this->assertSame([8, null], [(int)$row['delivery_version'], $row['queued_at']]);
        $this->assertNotNull($row['sent_at']);
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

    public function testTheStateLivesInAFewFlagRowsAndAWriteChangesOnlyItsKeys(): void
    {
        $this->db->insert($this->table('flag'), ['flag_code' => 'someone_else', 'flag_data' => '{"x":1}']);
        $store = new StateStore($this->resource);
        $this->assertSame([], $store->loadAll());
        $lock = 'SHA1(CONCAT(DATABASE(), ' . $this->db->quote('.' . $this->table('flag')) . '))';
        $this->db->fetchOne('SELECT GET_LOCK(' . $lock . ', 1)'); // re-entrant on the same connection
        $this->db->fetchOne('SELECT RELEASE_LOCK(' . $lock . ')');
        $store->save(['checks' => [0 => ['cron' => ['status' => 'ok']]], 'cursor:cron' => ['last' => 1],
            'cursor:log:system' => ['offset' => 5], 'alerted:0' => ['cron' => ['status' => 'fail', 'at' => 2]],
            'tally:2026102008' => [0 => []], 'tally:2026102009' => [0 => []], 'external' => ['a' => ['ok' => 1]]]);
        $this->assertSame('1', (string)$this->db->fetchOne('SELECT IS_FREE_LOCK(' . $lock . ')'),
            'one writer at a time, the lock released after each write');
        $codes = $this->db->fetchCol($this->db->select()->from($this->table('flag'), ['flag_code'])
            ->order('flag_code'));
        $this->assertSame(['commercekings_app_health_checks', 'commercekings_app_health_cursors',
            'commercekings_app_health_external', 'commercekings_app_health_tallies', 'commercekings_app_health_told',
            'someone_else'], $codes, 'grouped; another module\'s flag untouched');
        // the consumer writes what it told while the run writes its cursors: neither undoes the other
        $store->save(['alerted:0' => ['cron' => ['status' => 'ok', 'at' => 3]]]);
        $store->save(['cursor:cron' => ['last' => 4]]);
        $this->assertSame(['cron' => ['status' => 'ok', 'at' => 3]], $store->load(['alerted:0'])['alerted:0']);
        $this->assertSame(['cursor:cron' => ['last' => 4], 'cursor:log:system' => ['offset' => 5]],
            $store->loadPrefix('cursor:'));
        $store->delete('cursor:log:system');
        $this->assertSame(1, $store->deleteOldTallies((int)strtotime('2026-10-21 11:30:00 UTC')),
            'older than 26 hours: the 08 hour of the day before');
        $this->assertSame(['checks', 'cursor:cron', 'alerted:0', 'tally:2026102009', 'external'],
            array_keys($store->loadAll()));
        $this->assertSame('{"x":1}', $this->db->fetchOne($this->db->select()->from($this->table('flag'), ['flag_data'])
            ->where('flag_code = ?', 'someone_else')));
    }
}
