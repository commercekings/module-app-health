<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Config\Data;

/**
 * The sensitive-data rules in force: the rule sets of every module's etc/app_health_rules.xml (merged by code), then
 * the admin's "Rule settings" (a set or a rule - "pci" or "pci/card_number" - on or off, another severity) and
 * "Custom rules" (regex rules in a shipped or a new set). A rule without a usable pattern (its validator's, for
 * type="validator"), with an unknown validator or one RegexGuard refuses is left out.
 */
class Rules
{
    public const SETTINGS = 'commercekings_app_health/sensitive_data/rule_settings';
    public const CUSTOM = 'commercekings_app_health/sensitive_data/custom_rules';

    /** @var list<array<string, mixed>>|null */
    private ?array $rules = null;

    public function __construct(
        private readonly Data $ruleSets,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ValidatorPool $validators,
        private readonly RegexGuard $guard
    ) {
    }

    /**
     * @return list<array{key: string, set: string, setLabel: string, label: string, pattern: string,
     *     validator: ?string, severity: string, min: int, hint: string}> key = "<set>/<rule>"
     */
    public function all(): array
    {
        return $this->rules ??= $this->build();
    }

    /** @return list<array<string, mixed>> */
    private function build(): array
    {
        $sets = (array)$this->ruleSets->get();
        foreach ($this->rows(self::CUSTOM) as $row) {
            [$set, $code] = [$this->code($row['category'] ?? ''), $this->code($row['code'] ?? '')];
            if ($set === '' || $code === '') {
                continue;
            }
            $sets[$set] ??= ['label' => $set, 'severity' => 'warning', 'enabled' => true, 'hint' => '', 'rules' => []];
            $sets[$set]['rules'][$code] = ['label' => trim((string)($row['label'] ?? '')) ?: $code, 'type' => 'regex',
                'validator' => null, 'severity' => $this->severity($row['severity'] ?? ''), 'enabled' => true,
                'min' => max(1, (int)($row['min'] ?? 1)), 'pattern' => trim((string)($row['pattern'] ?? ''))];
        }
        $settings = [];
        foreach ($this->rows(self::SETTINGS) as $row) {
            $settings[trim((string)($row['code'] ?? ''))] = $row;
        }
        $rules = [];
        foreach ($sets as $setCode => $set) {
            $setSetting = $settings[$setCode] ?? [];
            if (!$this->enabled($set['enabled'] ?? true, $setSetting)) {
                continue;
            }
            foreach ((array)($set['rules'] ?? []) as $code => $rule) {
                $setting = $settings[$setCode . '/' . $code] ?? [];
                if (!$this->enabled($rule['enabled'] ?? true, $setting)) {
                    continue;
                }
                $validator = $rule['validator'] ? $this->validators->get((string)$rule['validator']) : null;
                if ($rule['validator'] && $validator === null) {
                    continue;
                }
                $pattern = ($rule['type'] ?? 'regex') === 'validator' && $validator !== null
                    ? $validator->getPattern() : (string)($rule['pattern'] ?? '');
                if ($this->guard->check($pattern, false) !== '') { // the benchmark ran when it was saved
                    continue;
                }
                $rules[] = ['key' => $setCode . '/' . $code, 'set' => (string)$setCode,
                    'setLabel' => (string)($set['label'] ?? $setCode), 'label' => (string)($rule['label'] ?? $code),
                    'pattern' => $pattern, 'validator' => $validator?->getCode(),
                    // the rule's setting, its own, its set's setting, its set's
                    'severity' => $this->severity($setting['severity'] ?? '')
                        ?? $this->severity($rule['severity'] ?? '') ?? $this->severity($setSetting['severity'] ?? '')
                        ?? $this->severity($set['severity'] ?? '') ?? 'warning',
                    'min' => max(1, (int)($rule['min'] ?? 1)), 'hint' => (string)($set['hint'] ?? '')];
            }
        }
        return $rules;
    }

    /** @return list<array<string, mixed>> an admin dynamic-rows setting (JSON) */
    private function rows(string $path): array
    {
        $value = $this->scopeConfig->getValue($path);
        $rows = is_string($value) && $value !== '' ? json_decode($value, true) : $value;
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string, mixed> $setting */
    private function enabled(bool $shipped, array $setting): bool
    {
        return isset($setting['enabled']) && $setting['enabled'] !== '' ? (bool)(int)$setting['enabled'] : $shipped;
    }

    private function severity(mixed $value): ?string
    {
        return in_array($value, ['critical', 'warning'], true) ? $value : null;
    }

    private function code(mixed $value): string
    {
        $code = trim((string)$value);
        return preg_match('/^[a-z0-9_]{1,40}$/D', $code) ? $code : '';
    }
}
