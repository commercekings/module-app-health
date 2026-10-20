<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Log;

use CommerceKings\AppHealth\Api\Data\LogEntry;
use CommerceKings\AppHealth\Model\Log\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    private Normalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new Normalizer();
    }

    private function safe(string $message, array $classes = [], ?string $file = null, ?int $line = null,
        string $level = 'ERROR', bool $withMessage = false): ?array
    {
        return $this->normalizer->safe(new LogEntry($level, 'main', $message, $classes, $file, $line),
            '/var/www/html', $withMessage);
    }

    public function testTheSameErrorWithOtherValuesIsOneFingerprint(): void
    {
        $a = $this->safe('Order 1234 of jane@example.com failed at https://a.test/x?id=1 for "Blue Shirt" 0xdeadbeef');
        $b = $this->safe('Order 99 of joe@example.net failed at https://b.test/y for "Red Hat" 0x1f');
        $this->assertSame($a['fingerprint'], $b['fingerprint']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $a['fingerprint']);
        $this->assertNotSame($a['fingerprint'], $this->safe('Order 1234 of jane@example.com shipped')['fingerprint']);
        $this->assertNotSame($a['fingerprint'], $this->safe('Order 1 of a@b.c failed at https://a.test for "x" 0x1',
            [], null, null, 'CRITICAL')['fingerprint'], 'another level group is another error');
    }

    public function testTheLabelIsTheClassElseTheFirstWordsMasked(): void
    {
        $this->assertSame('Magento\\Framework\\Exception\\LocalizedException',
            $this->safe('x', ['Magento\\Framework\\Exception\\LocalizedException'])['label']);
        $this->assertSame('Order *** of *** failed at ***',
            $this->safe('Order 1234 of jane@example.com failed at https://a.test')['label']);
        $this->assertSame('Customer *** not found for id ***',
            $this->safe('Customer Jane Doe not found for id 77')['label'], 'name-like words after the first');
        $this->assertSame('Could not save the cart *** because x',
            $this->safe('Could not save the cart 5 because x y z')['label'],
            'at most 8 words');
        $this->assertSame(['plain words', []], [$this->safe('plain words', ['evil class!'])['label'],
            $this->safe('plain words', ['evil class!'])['classes']], 'not a class name: not used');
    }

    public function testWhereItHappenedRelativeToTheRoot(): void
    {
        $this->assertSame('app/code/Vendor/Pay/Model/Gateway.php:120',
            $this->safe('x', [], '/var/www/html/app/code/Vendor/Pay/Model/Gateway.php', 120)['location']);
        $this->assertSame('…/lib/Helper.php:5', $this->safe('x', [], '/opt/other/lib/Helper.php', 5)['location'],
            'outside the root: the last two segments');
        $this->assertNull($this->safe('x', [], 'relative/path.php', 5)['location']);
        $this->assertNull($this->safe('x', [], '/etc/passwd', 1)['location'], 'PHP files only');
    }

    public function testTheMaskedMessage(): void
    {
        $masked = $this->normalizer->mask('Mail to jane@example.com (+1 202 555 0123) order 000000123 token'
            . ' sk_live_abcdef1234567890 at https://x.test/a "Blue Shirt" ok');
        $this->assertSame('Mail to *** (***) order *** token *** at *** *** ok', $masked);
        $this->assertSame(Normalizer::MESSAGE_LENGTH, mb_strlen($this->normalizer->mask(str_repeat('word ', 100))));
        $this->assertNull($this->safe('m')['message'], 'only when asked');
        $this->assertSame('m', $this->safe('m', [], null, null, 'ERROR', true)['message']);
    }

    public function testBelowWarningOrAnUnknownLevelIsLeftOut(): void
    {
        $this->assertNull($this->safe('x', [], null, null, 'INFO'));
        $this->assertNull($this->safe('x', [], null, null, 'whatever'));
        $this->assertSame(1, $this->safe('x', [], null, null, 'warning')['rank']);
        $this->assertSame([3, 'ALERT', 'CRITICAL'], [$this->safe('x', [], null, null, 'ALERT')['rank'],
            $this->safe('x', [], null, null, 'ALERT')['level'], Normalizer::group(3)]);
    }
}
