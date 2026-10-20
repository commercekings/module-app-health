<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Log\FileLogSource;
use Magento\Framework\App\Config\ScopeConfigInterface;

/** Admin: Stores > Configuration > CommerceKings > App health - global: every setting at the default scope. */
class Config
{
    public const SECTION = 'commercekings_app_health';
    private const GENERAL = self::SECTION . '/general/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /** App health on (the default scope: all of its settings are global). */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::GENERAL . 'enabled');
    }

    /** The global channel, Notification's channel code, e.g. "slack". */
    public function getChannel(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::GENERAL . 'channel'));
    }

    /** The global recipient on that channel, e.g. a Slack channel ID; '' when none is set. */
    public function getRecipient(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::GENERAL . 'recipient'));
    }

    /**
     * *Website channels*: a website's own channel and recipient for its own messages (its email settings, its unsent
     * sales emails, its daily summary); a website without a row uses the global ones.
     *
     * @return array<int, array{channel: string, recipient: string}> by website id
     */
    public function getWebsiteChannels(): array
    {
        $value = $this->scopeConfig->getValue(self::GENERAL . 'website_channels');
        $rows = is_string($value) && $value !== '' ? json_decode($value, true) : $value;
        $channels = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $websiteId = (int)($row['website_id'] ?? 0);
            $channel = trim((string)($row['channel'] ?? ''));
            $recipient = trim((string)($row['recipient'] ?? ''));
            if ($websiteId > 0 && $channel !== '' && $recipient !== '') {
                $channels[$websiteId] ??= ['channel' => $channel, 'recipient' => $recipient];
            }
        }
        return $channels;
    }

    /** *Also post shared problems to website channels* (off by default). */
    public function isSharedToWebsites(): bool
    {
        return $this->scopeConfig->isSetFlag(self::GENERAL . 'shared_to_websites');
    }

    public function isSummaryEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::GENERAL . 'summary');
    }

    /** Minutes after midnight (each summary in its own time zone); a Magento time field is stored as "08,00,00". */
    public function getSummaryMinute(): int
    {
        $parts = array_map('intval', explode(',', (string)$this->scopeConfig->getValue(self::GENERAL . 'summary_time'))
            + [0, 0]);
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
    /** OK runs in a row before a problem counts as recovered (at least 1). */
    public function getRecoverRuns(): int
    {
        return max(1, (int)($this->scopeConfig->getValue(self::GENERAL . 'recover_runs') ?: 2));
    }

    /** At most one message of a check's notices per this many minutes (per scope); 0: no limit. */
    public function getNoticeMinutes(): int
    {
        $value = $this->scopeConfig->getValue(self::GENERAL . 'notice_minutes');
        return $value === null || $value === '' ? 30 : max(0, (int)$value);
    }

    /** The hourly status: on by default (also the proof that alerts can go out, for the heartbeat). */
    public function isHourlyStatusEnabled(): bool
    {
        $value = $this->scopeConfig->getValue(self::GENERAL . 'hourly_status');
        return $value === null || $value === '' || (bool)(int)$value;
    }

    /** Minutes between two hourly statuses: 60 by default, 15 to 180 (the errors it lists are kept 3 hours). */
    public function getHourlyStatusMinutes(): int
    {
        return min(Fingerprints::RECENT_ERRORS_HOURS * 60,
            max(15, (int)($this->scopeConfig->getValue(self::GENERAL . 'hourly_status_minutes') ?: 60)));
    }

    public function getHeartbeatUrl(): string
    {
        $url = trim((string)$this->scopeConfig->getValue(self::GENERAL . 'heartbeat_url'));
        return preg_match('#^https?://[^\s/]+#i', $url) ? $url : '';
    }

    /** Alerts about log errors add their message, masked (off by default: type, count, time and file:line only). */
    public function isLogMessageIncluded(): bool
    {
        return $this->scopeConfig->isSetFlag(self::GENERAL . 'include_log_message');
    }

    /** @return string[] the extra var/log files the system log check reads (valid names only, at most 5) */
    public function getExtraLogFiles(): array
    {
        $names = preg_split('/[\s,]+/', (string)$this->scopeConfig->getValue(self::SECTION . '/system_log/extra_files'),
            -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = array_filter($names, fn ($name) => preg_match(FileLogSource::NAME, $name)
            && !in_array($name, ['system.log', 'exception.log'], true));
        return array_slice(array_values(array_unique($valid)), 0, 5);
    }

    /** @return string[] *Errors at once from*: the var/log files whose ERROR entries are sent at once, like CRITICAL */
    public function getImmediateLogFiles(): array
    {
        $names = preg_split('/[\s,]+/', (string)$this->scopeConfig->getValue(self::SECTION
            . '/system_log/immediate_files'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique(array_filter($names, fn ($name) => preg_match(FileLogSource::NAME, $name))));
    }

    /** A check without a setting (e.g. another module's) is on. */
    public function isCheckEnabled(string $code): bool
    {
        $value = $this->scopeConfig->getValue(self::SECTION . '/' . $code . '/enabled');
        return $value === null || $value === '' || (bool)(int)$value;
    }

    /** A check's whole-number setting, at least $min; $default when it is not set. */
    public function getInt(string $code, string $field, int $default, int $min = 1): int
    {
        $value = $this->scopeConfig->getValue(self::SECTION . '/' . $code . '/' . $field);
        return max($min, is_numeric($value) ? (int)$value : $default);
    }
}
