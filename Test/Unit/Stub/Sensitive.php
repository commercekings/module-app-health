<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Api\SensitiveDataValidatorInterface;
use CommerceKings\AppHealth\Model\Log\Normalizer;
use CommerceKings\AppHealth\Model\Sensitive\Config\Converter;
use CommerceKings\AppHealth\Model\Sensitive\Detector;
use CommerceKings\AppHealth\Model\Sensitive\RegexGuard;
use CommerceKings\AppHealth\Model\Sensitive\Rules;
use CommerceKings\AppHealth\Model\Sensitive\Validator\Iban;
use CommerceKings\AppHealth\Model\Sensitive\Validator\Luhn;
use CommerceKings\AppHealth\Model\Sensitive\ValidatorPool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Config\Data;
use Magento\Framework\Config\Dom;

/** The detector over the shipped etc/app_health_rules.xml (merged with $extraXml files), admin rows as JSON. */
trait Sensitive
{
    /**
     * @param string[] $extraXml more app_health_rules.xml contents, merged after the shipped one by code
     * @param array<string, SensitiveDataValidatorInterface> $extraValidators by code
     */
    private function detector(array $settings = [], array $custom = [], array $extraXml = [],
        array $extraValidators = []): Detector
    {
        $dom = new Dom((string)file_get_contents(dirname(__DIR__, 3) . '/etc/app_health_rules.xml'),
            new \Magento\Framework\Config\ValidationState\Configurable(false),
            ['/config/ruleset' => 'code', '/config/ruleset/rule' => 'code']);
        foreach ($extraXml as $xml) {
            $dom->merge($xml);
        }
        $sets = (new Converter())->convert($dom->getDom());
        $data = $this->createStub(Data::class);
        $data->method('get')->willReturn($sets);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => match ($path) {
            Rules::SETTINGS => json_encode($settings),
            Rules::CUSTOM => json_encode($custom),
            default => null,
        });
        $pool = new ValidatorPool(['luhn' => new Luhn(), 'iban' => new Iban()] + $extraValidators);
        return new Detector(new Rules($data, $scopeConfig, $pool, new RegexGuard()), $pool);
    }

    private function normalizer(): Normalizer
    {
        return new Normalizer($this->detector());
    }
}
