<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit;

use CommerceKings\AppHealth\Model\AlertPlan;
use CommerceKings\AppHealth\Model\Check\EmailFailing;
use CommerceKings\AppHealth\Model\Check\Exceptions;
use CommerceKings\AppHealth\Model\Check\SystemLog;
use CommerceKings\AppHealth\Model\ExceptionLog;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\Renderers;
use CommerceKings\AppHealth\Test\Unit\Stub\SlackMessages;
use CommerceKings\AppHealth\Test\Unit\Stub\Db;
use CommerceKings\AppHealth\Test\Unit\Stub\LogDir;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use PHPUnit\Framework\TestCase;

/**
 * Logs full of customer data in, only safe labels, class names, locations, counts and fingerprints out: nothing of a
 * log line reaches the state, a result or a message - and with the masked message on, no address, number, token, URL
 * or quoted value (names can remain there: README).
 */
class PrivacyTest extends TestCase
{
    use Db;
    use LogDir;
    use Pool;
    use Settings;

    private const MESSAGES_NOW = 1792483200;
    /** Never out, in either mode. */
    private const PRIVATE = ['jane.doe@example.com', '+1 202 555 0123', '4111 1111 1111 1111', '4111', 'xoxb',
        '123456789012', 'abcdefghijklmnopqrstuvwx', 'https://shop.example.test/checkout/cart?coupon=SECRET', 'SECRET',
        "'12 Main Street'", '"VIP-2026"', '000000123', '/var/www/html/', 'hunter2', 'jane doe', 'fe80::1'];
    /** Free text: out only in the label-only mode (the masked message keeps it - README). */
    private const NAMES = ['Jane', 'Doe', 'Main Street', 'SELECT * FROM customer_entity', 'Bearer'];

    protected function tearDown(): void
    {
        $this->removeLogDir();
    }

    /** @return string everything the run stored and every message it would send */
    private function runWith(bool $withMessage): string
    {
        $store = (new MemoryStore())->seed([Fingerprints::KEY => []]); // past its first day
        $state = new RunState($store);
        $clock = new Clock();
        $config = $this->config(['general/include_log_message' => $withMessage ? '1' : '0',
            'exceptions/count' => '2', 'exceptions/window_minutes' => '15', 'exceptions/max_read_kb' => '1024',
            'system_log/error_count' => '2', 'system_log/window_minutes' => '5', 'system_log/max_read_kb' => '1024',
            'email_failing/failed_reminders' => '3']);
        $reader = $this->logReader($state, $config);
        $fingerprints = new Fingerprints($state, $config);
        $deliveryState = $this->createStub(DeliveryStateInterface::class);
        $deliveryState->method('getTableName')->willReturn('ck_notification_delivery_state');
        $log = new ExceptionLog($reader);
        $checks = ['system_log' => new SystemLog($reader, $fingerprints, $config, $state, $clock),
            'exceptions' => new Exceptions($log, $fingerprints, $config, $state, $clock),
            'email_failing' => new EmailFailing($this->resource($this->db(['fetchPairs' => [[], []]])), $deliveryState,
                $log, $reader, $config, $clock, $state)];
        $run = function () use ($state, $checks): array {
            $state->begin();
            $results = array_map(fn ($check) => $check->run([])[0], $checks);
            $state->commit();
            return $results;
        };
        $run();
        $pii = 'Jane Doe <jane.doe@example.com>, +1 202 555 0123, card 4111 1111 1111 1111, token'
            . ' xo' . 'xb-123456789012-secret, Authorization: Bearer abcdefghijklmnopqrstuvwx,'
            . ' SELECT * FROM customer_entity,'
            . " referer https://shop.example.test/checkout/cart?coupon=SECRET, address '12 Main Street',"
            . ' coupon "VIP-2026", order 000000123';
        $this->appendLog(self::entry('Magento\\Framework\\Exception\\MailException', 'Unable to send mail to ' . $pii)
            . self::entry('Vendor\\Payment\\Exception\\CardDeclined', 'Declined: ' . $pii));
        $this->appendLog(self::line('CRITICAL', 'Customer Jane Doe could not pay: ' . $pii,
            '{"email":"jane.doe@example.com","file":"/var/www/html/app/code/Vendor/Pay/Model/Gateway.php:120"}')
            . self::line('CRITICAL', 'Payment gateway down for jane.doe@example.com in'
            . ' /var/www/html/vendor/vendor/pay/src/Client.php:88', '[]', 'payment')
            . self::line('WARNING', 'Slow response for ' . $pii)
            . self::line('ERROR', 'login failed: password hunter2 for customer jane doe from fe80::1'), 'system.log');
        $results = $run();
        $this->assertSame(['fail', 'fail', 'fail'], array_values(array_map(fn ($r) => $r->status, $results)));
        $target = new Target(0, 1, 'slack', 'C0', 'Shop', 'UTC');
        $messages = new SlackMessages(new Messages($this->pool(['system_log' => 'system.log', 'exceptions' => 'exception.log',
            'email_failing' => 'Sending']), new Clock(self::MESSAGES_NOW)));
        $out = [json_encode($store->rows)];
        $checksRow = [];
        foreach ($results as $code => $result) {
            $out[] = json_encode([$result->detail, $result->counts, $result->notices, $result->title]);
            $checksRow[$code] = ['status' => $result->status, 'detail' => $result->detail, 'title' => $result->title];
            $out[] = $messages->alert($target, $code, $checksRow[$code], AlertPlan::PROBLEM);
            $out[] = $messages->notices($target, $code, $result->notices);
        }
        $tally = array_map(fn ($result) => ['w' => 2, 'c' => $result->counts], $results);
        $out[] = $messages->summary($target, $checksRow, [$tally], $clock->now, $store->row(Fingerprints::KEY) ?? []);
        $teamMessage = $this->createStub(\CommerceKings\AppHealth\Model\Notification\TeamMessage::class);
        $teamMessage->method('deliver')->willReturnCallback(function ($type, $ref, \Closure $compose) use (
            $target,
            &$out
        ) {
            $out[] = $compose($target, ['sent_at' => null])[0];
            return true;
        });
        $store->seed(['checks' => [0 => $checksRow]]);
        (new \CommerceKings\AppHealth\Model\Notification\Status($teamMessage, $store, $messages->messages, $this->pool([
            'system_log' => 'system.log', 'exceptions' => 'exception.log', 'email_failing' => 'Sending']), $config,
            new Clock(self::MESSAGES_NOW), new \CommerceKings\AppHealth\Model\Status\DefaultPolicy($config),
            Renderers::pool()))->process('0');
        return implode("\n", $out);
    }

    public function testOnlySafeLabelsLeaveTheLogs(): void
    {
        $everything = $this->runWith(false);
        foreach (array_merge(self::PRIVATE, self::NAMES) as $private) {
            $this->assertStringNotContainsString($private, $everything, $private);
        }
        $this->assertStringContainsString('Vendor\\\\Payment\\\\Exception\\\\CardDeclined', $everything);
        $this->assertStringContainsString('app/code/Vendor/Pay/Model/Gateway.php:120', $everything, 'where, relative');
        $this->assertStringContainsString('Critical: Customer *** could (', $everything, 'names masked, 3 words');
        $this->assertStringContainsString('Critical: Payment gateway down (', $everything);
        $this->assertStringContainsString("New errors this hour: 1\n• login failed: password (1×, new)", $everything,
            'an error: in the hourly status');
        $this->assertStringContainsString('1 new mail transport error', $everything);
        $this->assertStringNotContainsString('Message: ', $everything, 'off (the default): no message line');
        $this->assertMatchesRegularExpression('/Critical: Payment gateway down \([^)]+\)\nWhere: [^\n]+:\d+/',
            $everything, 'label, count and time, then Where');
    }

    public function testTheMaskedMessageKeepsNoAddressNumberTokenUrlOrQuote(): void
    {
        $everything = $this->runWith(true);
        foreach (self::PRIVATE as $private) {
            $this->assertStringNotContainsString($private, $everything, $private);
        }
        $this->assertStringContainsString("Where: app/code/Vendor/Pay/Model/Gateway.php:120\n"
            . 'Message: Customer *** could not pay: Jane Doe <***>, ***', $everything,
            'the message, masked: a name after "customer" masked, other capitalized names remain (README)');
        $this->assertStringContainsString('login failed: password *** for customer *** from ***', $everything,
            'kept masked in the module\'s table');
    }
}
