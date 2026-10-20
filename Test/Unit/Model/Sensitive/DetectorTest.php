<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Sensitive;

use CommerceKings\AppHealth\Test\Unit\Stub\Sensitive;
use PHPUnit\Framework\TestCase;

/** Every shipped rule on what it is for, and on what it must leave alone. */
class DetectorTest extends TestCase
{
    use Sensitive;

    /** @return string[] the rule keys found */
    private function found(string $text): array
    {
        return array_keys($this->detector()->detect($text));
    }

    public function testEachRuleFindsItsData(): void
    {
        $cases = [
            'pci/card_number' => 'Payment for order 000000123 with card 4111 1111 1111 1111 declined',
            'pci/card_cvv' => '{"cc_number":"x","cvv":"123"}',
            'pci/card_track' => 'swipe %B4111111111111111^DOE/JANE^2512101000000000?',
            // key-shaped values built from parts: no secret-scanner false alarm on the source
            'credentials/private_key' => '-----BEGIN RSA ' . 'PRIVATE KEY-----' . "\nMIIE...",
            'credentials/jwt' => 'token eyJ' . 'hbGciOiJIUzI1NiJ9.eyJ' . 'zdWIiOiIxMjM0NTY3ODkwIn0'
                . '.dozjgNryP4J3jVmNHl0w5N',
            'credentials/auth_header' => 'headers: {"Authorization": "Bearer abcdefghijklmnop123"}',
            'credentials/password_field' => 'login {"username":"jane","password":"hunter22"}',
            'credentials/api_key' => 'Stripe call with sk_' . 'live_' . str_repeat('x1', 12) . ' failed',
            'personal/iban' => 'refund to DE89 3704 0044 0532 0130 00',
            'personal/bulk_emails' => 'to a@x.io, b@x.io, c@x.io, d@x.io, e@x.io',
            'personal/bulk_phones' => 'call +1 202 555 0101, +1 202 555 0102, +1 202 555 0103, +1 202 555 0104,'
                . ' +1 202 555 0105',
        ];
        foreach ($cases as $rule => $text) {
            $this->assertContains($rule, $this->found($text), $rule);
        }
        $this->assertSame(['pci/card_number' => 2], $this->detector()->detect('4111111111111111 and 5555555555554444'),
            'counted');
    }

    public function testWhatItLeavesAlone(): void
    {
        foreach ([
            'Order 000000123 placed at 1792483200123, invoice 4111111111111112',
            'Cron Job sales_send_order_emails is run; quote 123456789012345',
            '{"password":"***"} password: ****',
            'cvv field missing; cvv_length=3 chars',
            'to a@x.io, b@x.io, c@x.io, d@x.io',
            'build 2026.10.20.0801021234567 deployed',
            'IBAN DE89370400440532013001 (a typo)',
        ] as $text) {
            $this->assertSame([], $this->found($text), $text);
        }
    }

    public function testARuleHittingTheBacktrackingLimitIsFlaggedNotSilentlySkipped(): void
    {
        $detector = $this->detector([], [['code' => 'slow', 'category' => 'internal', 'pattern' => '/(?:\w|\d)*x/']]);
        $detector->detect(substr(\CommerceKings\AppHealth\Model\Sensitive\RegexGuard::sample(), -3000));
        $this->assertSame(['internal/slow' => 1], $detector->takeSlowRules());
        $this->assertSame([], $detector->takeSlowRules(), 'taken once');
    }

    public function testADeadlineStopsTheScan(): void
    {
        $detector = $this->detector();
        $detector->until(microtime(true) - 1);
        $this->assertSame([], $detector->detect('card 4111 1111 1111 1111'));
        $this->assertTrue($detector->wasCut());
        $detector->until(null);
        $this->assertNotSame([], $detector->detect('card 4111 1111 1111 1111'));
        $this->assertFalse($detector->wasCut());
    }

    public function testACutScanSaysWhichRuleRanAndTheSlowest(): void
    {
        $time = 0.0;
        $base = $this->detector();
        $detector = new \CommerceKings\AppHealth\Model\Sensitive\Detector(
            (new \ReflectionProperty($base, 'rules'))->getValue($base),
            (new \ReflectionProperty($base, 'validators'))->getValue($base),
            function () use (&$time): float {
                return $time++; // every reading of the clock a second later
            }
        );
        $detector->until(2.5);
        $detector->detect('card 4111 1111 1111 1111');
        $this->assertTrue($detector->wasCut());
        $first = array_key_first($detector->rules());
        $this->assertSame(['rule' => $first, 'slowest' => [$first => 1000.0]], $detector->takeCut(),
            'the first rule took a second (by the clock), the deadline passed before the second');
        $this->assertSame(['rule' => null, 'slowest' => []], $detector->takeCut(), 'taken once');
    }

    public function testALongEntryIsScannedFastAndCapped(): void
    {
        $text = str_repeat('Lorem ipsum dolor sit amet 12345 ', 2000) . ' 4111 1111 1111 1111';
        $start = microtime(true);
        $this->assertSame([], $this->found($text), 'beyond the first 16 KB');
        $this->assertLessThan(0.5, microtime(true) - $start);
    }
}
