<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/** Admin: Stores > Configuration > CommerceKings > App health. */
class Config
{
    public const SECTION = 'commercekings_app_health';
    private const GENERAL = self::SECTION . '/general/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Alerts on: in the default scope (website 0) for the shop-wide checks, in a website's scope for its own checks
     * and its daily summary (a website inherits the default).
     */
    public function isEnabled(int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag(self::GENERAL . 'enabled', ...$this->scope($websiteId));
    }

    /** Notification's channel code, e.g. "slack"; website 0 = the default scope. */
    public function getChannel(int $websiteId): string
    {
        return trim((string)$this->scopeConfig->getValue(self::GENERAL . 'channel', ...$this->scope($websiteId)));
    }

    /** The address on the channel, e.g. a Slack channel ID; '' when none is set. Website 0 = the default scope. */
    public function getRecipient(int $websiteId): string
    {
        return trim((string)$this->scopeConfig->getValue(self::GENERAL . 'recipient', ...$this->scope($websiteId)));
    }

    public function isSummaryEnabled(int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag(self::GENERAL . 'summary', ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    /** Minutes after midnight in the website's time zone; a Magento time field is stored as "08,00,00". */
    public function getSummaryMinute(int $websiteId): int
    {
        $parts = array_map('intval', explode(',', (string)$this->scopeConfig->getValue(self::GENERAL . 'summary_time',
            ScopeInterface::SCOPE_WEBSITE, $websiteId)) + [0, 0]);
        return min(23, max(0, $parts[0])) * 60 + min(59, max(0, $parts[1] ?? 0));
    }

    /** A check still failing is repeated after this many hours; at least 1. */
    public function getRepeatHours(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::GENERAL . 'repeat_hours'));
    }

    /**
     * The heartbeat URL; '' when none is set or it is not http(s). Stored encrypted; config.xml's backend_model makes
     * Magento decrypt it on reading.
     */
    public function getHeartbeatUrl(): string
    {
        $url = trim((string)$this->scopeConfig->getValue(self::GENERAL . 'heartbeat_url'));
        return preg_match('#^https?://[^\s/]+#i', $url) ? $url : '';
    }

    /** A check without a setting (e.g. another module's) is on. */
    public function isCheckEnabled(string $code): bool
    {
        $value = $this->scopeConfig->getValue(self::SECTION . '/' . $code . '/enabled');
        return $value === null || $value === '' || (bool)(int)$value;
    }

    /** @return array{0: string, 1?: int} getValue()'s scope arguments; website 0 = the default scope */
    private function scope(int $websiteId): array
    {
        return $websiteId > 0 ? [ScopeInterface::SCOPE_WEBSITE, $websiteId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT];
    }

    /** A check's whole-number setting, at least $min; $default when it is not set. */
    public function getInt(string $code, string $field, int $default, int $min = 1): int
    {
        $value = $this->scopeConfig->getValue(self::SECTION . '/' . $code . '/' . $field);
        return max($min, is_numeric($value) ? (int)$value : $default);
    }
}
