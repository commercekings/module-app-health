<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit;

use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Check\EmailFailing;
use CommerceKings\AppHealth\Model\Check\Exceptions;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\LogDir;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use PHPUnit\Framework\TestCase;

/**
 * Nothing of a log line reaches the state, a result or a message: exception.log full of customer data gives class
 * names and counts only.
 */
class PrivacyTest extends TestCase
{
    private const MESSAGES_NOW = 1792483200;

    use Db;
    use LogDir;
    use Pool;
    use Settings;

    private const PRIVATE = ['jane.doe@example.com', 'Jane', 'Doe', '+1 202 555 0123', '12 Main Street', '4111',
        'xoxb-123-secret', 'SELECT', 'customer_entity', 'checkout/cart', '/var/www', 'order #000000123'];

    protected function tearDown(): void
    {
        $this->removeLogDir();
    }

    public function testOnlyClassNamesAndCountsLeaveTheLog(): void
    {
        $store = new MemoryStore();
        $state = new RunState($store);
        $clock = new Clock();
        $config = $this->config(['exceptions/count' => '2', 'exceptions/window_minutes' => '15',
            'exceptions/max_read_kb' => '1024', 'email_failing/failed_reminders' => '3']);
        $log = new ExceptionLog($this->logFilesystem(), $state, $config);
        $deliveryState = $this->createStub(DeliveryStateInterface::class);
        $deliveryState->method('getTableName')->willReturn('ck_notification_delivery_state');
        $checks = [new Exceptions($log, $config, $state, $clock),
            new EmailFailing($this->resource($this->db(['fetchPairs' => [[], []]])), $deliveryState, $log, $config,
                $clock)];
        $run = function () use ($state, $checks): array {
            $state->begin();
            $results = array_map(fn ($check) => $check->run(), $checks);
            $state->commit();
            return $results;
        };
        $run();
        $this->appendLog(self::entry('Magento\\Framework\\Exception\\MailException',
            'Unable to send mail to Jane Doe <jane.doe@example.com>, +1 202 555 0123, 12 Main Street')
            . self::entry('Vendor\\Payment\\Exception\\CardDeclined', 'card 4111 of order #000000123 declined, token '
            . 'xoxb-123-secret, SELECT * FROM customer_entity, referer checkout/cart')
            . "[2026-10-20T08:01:03.000000+00:00] main.CRITICAL: Jane Doe jane.doe@example.com [] []\n");
        [$exceptions, $sending] = $run();
        $this->assertSame('fail', $exceptions->status);
        $this->assertSame('fail', $sending->status);
        $checksRow = [0 => ['exceptions' => ['status' => $exceptions->status, 'detail' => $exceptions->detail],
            'email_failing' => ['status' => $sending->status, 'detail' => $sending->detail]]];
        $target = new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC');
        $messages = new Messages($this->pool(['exceptions' => 'Exceptions', 'email_failing' => 'Sending']),
            new Clock(self::MESSAGES_NOW));
        $everything = json_encode([$exceptions, $sending, $store->rows]) . "\n"
            . $messages->alert($target, $checksRow[0], AlertPlan::changes($checksRow[0], [], $clock->now, 3600)) . "\n"
            . $messages->summary($target, $checksRow[0], [['exceptions' => ['w' => 2, 'c' => $exceptions->counts],
                'email_failing' => ['w' => 2, 'c' => $sending->counts]]], $clock->now);
        foreach (self::PRIVATE as $private) {
            $this->assertStringNotContainsString($private, $everything);
        }
        $this->assertStringContainsString('Vendor\\\\Payment\\\\Exception\\\\CardDeclined', $everything);
        $this->assertStringContainsString('1 new mail transport error', $everything);
    }
}
