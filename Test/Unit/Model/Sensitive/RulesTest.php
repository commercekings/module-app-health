<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Sensitive;

use CommerceKings\AppHealth\Api\SensitiveDataValidatorInterface;
use CommerceKings\AppHealth\Model\Config\Backend\SensitiveRules;
use CommerceKings\AppHealth\Model\Sensitive\RegexGuard;
use CommerceKings\AppHealth\Test\Unit\Stub\Sensitive;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/** Rule sets from XML (merged by code across modules), then the admin's settings and custom rules. */
class RulesTest extends TestCase
{
    use Sensitive;

    /** @return array<string, string> rule key => severity */
    private function inForce(...$args): array
    {
        return array_map(fn ($rule) => $rule['severity'], $this->detector(...$args)->rules());
    }

    public function testTheShippedSets(): void
    {
        $this->assertSame(['pci/card_number' => 'critical', 'pci/card_cvv' => 'critical',
            'pci/card_track' => 'critical',
            'credentials/private_key' => 'critical', 'credentials/jwt' => 'critical',
            'credentials/auth_header' => 'critical', 'credentials/password_field' => 'critical',
            'credentials/api_key' => 'critical', 'personal/iban' => 'warning', 'personal/bulk_emails' => 'warning',
            'personal/bulk_phones' => 'warning'], $this->inForce());
    }

    public function testAnotherModulesFileAddsASetWithItsValidatorOverridesARuleAndTurnsASetOff(): void
    {
        $validator = new class implements SensitiveDataValidatorInterface {
            public function getCode(): string
            {
                return 'fixture_loyalty';
            }

            public function getPattern(): string
            {
                return '/\bLC-\d{8}\b/';
            }

            public function isValid(string $match): bool
            {
                return (int)substr($match, -1) % 2 === 0;
            }
        };
        $detector = $this->detector([], [], [(string)file_get_contents(dirname(__DIR__, 2)
            . '/_files/app_health_rules.xml')], [$validator]);
        $rules = $detector->rules();
        $this->assertArrayNotHasKey('personal/iban', $rules, 'its set turned off');
        $this->assertSame('warning', $rules['pci/card_cvv']['severity'], 'overridden by code');
        $this->assertSame(['Loyalty', 'loyalty card number', 'critical'], [$rules['loyalty/loyalty_card']['setLabel'],
            $rules['loyalty/loyalty_card']['label'], $rules['loyalty/loyalty_card']['severity']]);
        $this->assertSame(['loyalty/loyalty_card' => 1], $detector->detect('cards LC-12345678 and LC-12345677'),
            'its validator, by name from the XML');
    }

    public function testTheFileValidatesAgainstTheSchema(): void
    {
        $schema = dirname(__DIR__, 4) . '/etc/app_health_rules.xsd';
        $files = [dirname(__DIR__, 4) . '/etc/app_health_rules.xml',
            dirname(__DIR__, 2) . '/_files/app_health_rules.xml'];
        foreach ($files as $file) {
            $dom = new \DOMDocument();
            $dom->load($file);
            $this->assertTrue($dom->schemaValidate($schema), $file);
        }
        $bad = new \DOMDocument();
        $bad->loadXML('<config><ruleset code="Bad Code"/></config>');
        $errors = libxml_use_internal_errors(true);
        $this->assertFalse($bad->schemaValidate($schema));
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
    }

    public function testAdminSettingsTurnSetsAndRulesOffAndChangeSeverity(): void
    {
        $rules = $this->inForce([['code' => 'personal', 'enabled' => '0'],
            ['code' => 'pci/card_track', 'enabled' => '0'],
            ['code' => 'credentials/jwt', 'severity' => 'warning'], ['code' => 'pci', 'severity' => 'warning']]);
        $this->assertArrayNotHasKey('personal/iban', $rules);
        $this->assertArrayNotHasKey('pci/card_track', $rules);
        $this->assertSame('warning', $rules['credentials/jwt']);
        $this->assertSame('warning', $rules['pci/card_number'], 'the set\'s setting, for its rules without their own');
        $this->assertSame('critical', $rules['credentials/api_key']);
        $this->assertArrayHasKey('personal/iban', $this->inForce([], [], ["<config><ruleset code=\"personal\""
            . " enabled=\"false\"/></config>"]) + $this->inForce([['code' => 'personal', 'enabled' => '1']]),
            'the admin can turn a set back on');
    }

    public function testCustomRulesAndUnsafeOnesLeftOut(): void
    {
        $detector = $this->detector([], [
            ['code' => 'staff_pin', 'category' => 'internal', 'label' => 'staff PIN', 'pattern' => '/\bPIN=\d{6}\b/',
                'severity' => 'critical', 'min' => '1'],
            ['code' => 'slow', 'category' => 'internal', 'pattern' => '/(a+)+$/'],
            ['code' => 'broken', 'category' => 'internal', 'pattern' => '/[unclosed/'],
            ['code' => 'no_delimiters', 'category' => 'pci', 'pattern' => 'PIN=\d+'],
        ]);
        $this->assertSame(['internal/staff_pin' => 1], array_intersect_key($detector->detect('login PIN=123456'),
            ['internal/staff_pin' => 1]));
        $rules = $detector->rules();
        $this->assertSame(['internal', 'staff PIN'], [$rules['internal/staff_pin']['setLabel'],
            $rules['internal/staff_pin']['label']]);
        foreach (['internal/slow', 'internal/broken', 'pci/no_delimiters'] as $refused) {
            $this->assertArrayNotHasKey($refused, $rules);
        }
    }

    public function testTheRegexGuard(): void
    {
        $guard = new RegexGuard();
        $this->assertSame('', $guard->check('/\bsecret_[0-9]{8}\b/i'));
        $this->assertStringContainsString('compile', $guard->check('/[a/'));
        $this->assertStringContainsString('delimiters', $guard->check('secret'));
        $this->assertStringContainsString('nested quantifier', $guard->check('/(\w+\s?)*$/'));
        $this->assertStringContainsString('characters', $guard->check('/' . str_repeat('a', 400) . '/'));
        $this->assertStringContainsString('realistic log sample', $guard->check('/(?:\w|\d)*x/'),
            'no nested quantifier, fine on long runs of one character, but not on a mixed log');
        $this->assertSame('', $guard->check('/(?:\w|\d)*x/', false), 'the cheap check (every run) skips the sample');
        $this->assertStringContainsString('too slow on long lines', $guard->check('/\w*\w*\w*secret_\d/', false),
            'fast while its literal is missing, but not after a long word run that ends in it');
        $this->assertStringContainsString('too slow', (new RegexGuard(0.0))->check('/\bsecret\b/'),
            'slower than the time limit on the sample');
        foreach ($this->detector()->rules() as $key => $rule) {
            $this->assertSame('', $guard->check($rule['pattern']), 'shipped rule ' . $key . ' passes');
        }
    }

    public function testTheAdminCannotSaveAnUnsafeRule(): void
    {
        $backend = (new \ReflectionClass(SensitiveRules::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SensitiveRules::class, 'guard'))->setValue($backend, new RegexGuard());
        foreach ([
            [['code' => 'x', 'category' => 'internal', 'pattern' => '/(a+)+/']],
            [['code' => 'Bad Code', 'category' => 'internal', 'pattern' => '/x/']],
            [['code' => 'pci/card_cvv', 'severity' => 'urgent']],
            [['code' => 'pci', 'enabled' => 'yes']],
        ] as $rows) {
            $backend->setData('value', $rows); // dynamic rows arrive as an array
            try {
                $backend->beforeSave();
                $this->fail('saved ' . json_encode($rows));
            } catch (LocalizedException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
