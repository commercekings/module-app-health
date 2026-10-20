<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\SensitiveData;
use CommerceKings\AppHealth\Model\Messages;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\Target;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\LogDir;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Pool;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use PHPUnit\Framework\TestCase;

/** Sensitive data in the logs: a high alert at once, the rule and where - and the value nowhere. */
class SensitiveDataTest extends TestCase
{
    use LogDir;
    use Pool;
    use Settings;

    private const VALUES = ['4111 1111 1111 1111', '4111', 'hunter22', 'sk_' . 'live_' . 'x1x1x1x1x1x1x1x1x1x1x1x1',
        'DE89 3704 0044 0532 0130 00', '0532'];

    private MemoryStore $store;
    private RunState $state;
    private SensitiveData $check;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->state = new RunState($this->store);
        $this->clock = new Clock(1792483260);
        $this->logClock = $this->clock;
        $config = $this->config(['system_log/max_read_kb' => '1024', 'exceptions/max_read_kb' => '1024',
            'general/include_log_message' => '1']);
        $this->check = new SensitiveData($this->logReader($this->state, $config), $this->detector(), $config,
            $this->state, $this->clock);
    }

    protected function tearDown(): void
    {
        $this->removeLogDir();
    }

    private function run5(string $system = '', string $exception = ''): CheckResult
    {
        $this->appendLog($system, 'system.log');
        $this->appendLog($exception);
        $this->state->begin();
        $result = $this->check->run();
        $this->state->commit();
        $this->clock->now += 300;
        return $result;
    }

    public function testAFindingIsAHighAlertWithoutTheValue(): void
    {
        $this->run5();
        $result = $this->run5(self::line('INFO', 'Gateway request card 4111 1111 1111 1111 cvv ok', '{"file":'
            . '"/var/www/html/app/code/Vendor/Payment/Model/Gateway.php:120"}', 'payment')
            . self::line('INFO', 'Gateway request card 4111 1111 1111 1111 cvv ok', '{"file":'
                . '"/var/www/html/app/code/Vendor/Payment/Model/Gateway.php:120"}', 'payment')
            . self::line('ERROR', 'Refund to DE89 3704 0044 0532 0130 00 failed'),
            self::entry('Vendor\\\\Api\\\\AuthException', 'Login {"password":"hunter22"} with key'
                . ' sk_' . 'live_' . 'x1x1x1x1x1x1x1x1x1x1x1x1'));
        $this->assertSame(CheckResult::OK, $result->status, 'news, not a status');
        $this->assertSame(['found' => 7, 'found:pci.card_number' => 2, 'found:personal.iban' => 1,
            'found:credentials.password_field' => 2, 'found:credentials.api_key' => 2], $result->counts,
            'each match: the exception entry carries its message twice (message and context)');
        $notices = implode("\n", $result->notices);
        $this->assertStringContainsString('PCI: payment card number in system.log (2×, last {t:',
            $notices);
        $this->assertStringContainsString(")\nWhere: app/code/Vendor/Payment/Model/Gateway.php:120\n", $notices,
            'below WARNING too: scanned, where it happened');
        $this->assertStringContainsString('Credentials: password in exception.log', $notices);
        $this->assertStringContainsString('Personal data: bank account number (IBAN) in system.log',
            $notices);
        $this->assertStringContainsString('Check: Stop logging it at the source, then rotate or remove the log: card'
            . ' data in logs breaks PCI DSS.', $notices);
        $text = (new Messages($this->pool(['sensitive_data' => 'Sensitive data in logs']), $this->clock))
            ->notices(new Target(0, 1, 'slack', 'C0', 'Main Website Store', 'UTC'), 'sensitive_data',
                $result->notices);
        $this->assertStringStartsWith("*Security: sensitive data in logs · Main Website Store (all websites)*\n\n"
            . "PCI: payment card number in system.log (2×, last 08:01)\n"
            . 'Where: app/code/Vendor/Payment/Model/Gateway.php:120'
            . "\n\nCredentials: password in exception.log", $text);
        $this->assertStringContainsString("\n\nWhat to do: Stop logging it at the source, then rotate or remove the"
            . ' log: card data in logs breaks PCI DSS.', $text);
        $everything = $text . json_encode($result) . json_encode($this->store->rows);
        foreach (self::VALUES as $value) {
            $this->assertStringNotContainsString($value, $everything, $value);
        }
    }

    public function testToldOnceThenAgainAfterTheRepeatInterval(): void
    {
        $this->run5();
        $card = self::line('ERROR', 'card 4111111111111111 declined');
        $this->assertNotSame([], $this->run5($card)->notices);
        $this->assertSame([], $this->run5($card)->notices, 'told already');
        $this->clock->now += 6 * 3600;
        $this->assertNotSame([], $this->run5(str_replace('08:01:02', gmdate('H:i:s', $this->clock->now), $card))
            ->notices, 'still going on after the repeat interval');
    }
}
