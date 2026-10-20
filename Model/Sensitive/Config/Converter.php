<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive\Config;

use Magento\Framework\Config\ConverterInterface;

/**
 * The merged etc/app_health_rules.xml as rule sets: code => [label, severity, enabled, sortOrder, hint, rules =>
 * code => [label, type, validator, severity, enabled, min, pattern]]. Attributes left out stay null, so the rule
 * inherits its set's severity.
 */
class Converter implements ConverterInterface
{
    /**
     * @param \DOMDocument $source
     * @return array<string, array<string, mixed>>
     */
    public function convert($source)
    {
        $sets = [];
        foreach ($source->getElementsByTagName('ruleset') as $set) {
            $rules = [];
            foreach ($set->getElementsByTagName('rule') as $rule) {
                $pattern = $rule->getElementsByTagName('pattern')->item(0);
                $rules[$rule->getAttribute('code')] = [
                    'label' => $rule->getAttribute('label') ?: $rule->getAttribute('code'),
                    'type' => $rule->getAttribute('type') ?: 'regex',
                    'validator' => $rule->getAttribute('validator') ?: null,
                    'severity' => $rule->getAttribute('severity') ?: null,
                    'enabled' => $this->flag($rule, 'enabled'),
                    'min' => max(1, (int)($rule->getAttribute('min') ?: 1)),
                    'pattern' => $pattern !== null ? trim((string)$pattern->textContent) : null,
                ];
            }
            $hint = $set->getElementsByTagName('hint')->item(0);
            $sets[$set->getAttribute('code')] = [
                'label' => $set->getAttribute('label') ?: $set->getAttribute('code'),
                'severity' => $set->getAttribute('severity') ?: 'warning',
                'enabled' => $this->flag($set, 'enabled'),
                'sortOrder' => (int)$set->getAttribute('sortOrder'),
                'hint' => $hint !== null ? trim((string)$hint->textContent) : '',
                'rules' => $rules,
            ];
        }
        uasort($sets, fn ($a, $b) => $a['sortOrder'] <=> $b['sortOrder']);
        return $sets;
    }

    private function flag(\DOMElement $element, string $name): bool
    {
        return !$element->hasAttribute($name) || in_array($element->getAttribute($name), ['true', '1'], true);
    }
}
