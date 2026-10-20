<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;

/** The module's Config over settings by path (config.xml's defaults unless the test sets one). */
trait Settings
{
    /** @param array<string, mixed> $values path (after "commercekings_app_health/") => value */
    private function config(array $values = []): Config
    {
        $values += ['general/repeat_hours' => '6'];
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn ($path) => $values[substr((string)$path, strlen(Config::SECTION) + 1)] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn ($path) => !empty($values[substr((string)$path, strlen(Config::SECTION) + 1)])
        );
        return new Config($scopeConfig);
    }
}
