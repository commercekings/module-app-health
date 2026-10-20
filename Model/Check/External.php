<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Check;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\Data\Cause;
use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\External\ReportValidator;
use CommerceKings\AppHealth\Model\External\StatusFiles;
use CommerceKings\AppHealth\Model\Format;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * What the servers report in var/app-health/status-<source>.json (StatusFiles): each "fail" line is a problem and
 * each "warn" line a warning of this check; a server whose last status is older than "stale_minutes" (default 15) is
 * a problem of its own - its script, its cron or the server has stopped - and so is one whose file is gone, until a
 * day after its last status. A file that breaks a rule (ReportValidator) is a warning naming the file and the rule,
 * never its content; the server's last good status still counts. OK and silent while no server has reported.
 *
 * A file is validated when it changes (its hash), and a status must be newer than the last one taken from it, so an
 * old copy put back is refused. The last good status per server is kept in one state row, "external" (source =>
 * status; at most StatusFiles::MAX_FILES), written only when it changed.
 *
 * One check for all servers: a line failing while the check already fails is told once as a notice ("Also failing").
 */
class External implements CheckInterface
{
    public const CODE = 'external';
    /** The state row of the servers' last good statuses. */
    public const ROW = 'external';
    /** A server whose file is gone is forgotten a day after its last status. */
    public const FORGET_SECONDS = 86400;
    private const CURSOR = 'cursor:' . self::CODE;

    public function __construct(
        private readonly StatusFiles $files,
        private readonly ReportValidator $validator,
        private readonly Config $config,
        private readonly RunState $state,
        private readonly DateTime $dateTime
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getHint(): string
    {
        return (string)__('Look at the server named above. No status at all: is the status script still scheduled'
            . ' on the server (cron or systemd timer), and can it write var/app-health?');
    }

    public function getLabel(): string
    {
        return 'Server status';
    }

    public function run(): CheckResult
    {
        $now = (int)$this->dateTime->gmtTimestamp();
        $staleAfter = $this->config->getInt(self::CODE, 'stale_minutes', 15) * 60;
        try {
            $read = $this->files->read();
        } catch (FileSystemException) {
            return CheckResult::warn('var/app-health could not be read or created', [], 'Server status unreadable')
                ->withCauses([new Cause('external_unreadable', 'var/' . StatusFiles::DIR . ' could not be read or'
                    . ' created', (string)__('Check that the web server\'s user can write var/ and var/app-health.'))]);
        }
        $stored = $this->state->get(self::ROW) ?? [];
        $reports = $stored;
        $invalid = [];
        /** @var array<string, string> $refused a refused file's short form: its name and its first rule */
        $refused = [];
        $present = [];
        foreach ($read['files'] as $name => $file) {
            $source = $file['source'];
            $present[$source] = true;
            try {
                $report = $this->take($source, $file['json'], $reports[$source] ?? null, $now, $staleAfter);
                if ($report !== null) {
                    $reports[$source] = $report;
                }
            } catch (\InvalidArgumentException $e) {
                $invalid[$source . '/!'] = new Cause('external_invalid', $name . ': ' . $e->getMessage());
                $refused[$source . '/!'] = $name . ' refused: ' . explode('; ', $e->getMessage())[0];
            }
        }
        if ($read['more'] > 0) {
            $invalid['var/app-health/!'] = new Cause('external_too_many', sprintf('%d more status files left out (at most %d)',
                $read['more'], StatusFiles::MAX_FILES));
            $refused['var/app-health/!'] = sprintf('%d status files over %d', $read['more'], StatusFiles::MAX_FILES);
        }

        $failing = [];
        $warning = [];
        /** @var array<string, string> $titles a cause's short form, without times or details */
        $titles = [];
        $ok = 0;
        $servers = 0;
        ksort($reports);
        foreach ($reports as $source => $row) {
            $source = (string)$source;
            $generatedAt = (int)($row['generated_at'] ?? 0);
            if (!isset($present[$source]) && $generatedAt <= $now - self::FORGET_SECONDS) {
                unset($reports[$source]);
                continue;
            }
            $servers++;
            if ($generatedAt <= $now - $staleAfter || !isset($present[$source])) {
                $failing[$source . '/'] = new Cause('external_stale', 'No status from ' . $source . ' since '
                    . Format::at($generatedAt) . (isset($present[$source]) ? '' : ' (its status file is gone)'));
                $titles[$source . '/'] = 'No status from ' . $source;
                continue;
            }
            foreach ((array)($row['checks'] ?? []) as $line) {
                $status = (string)($line['status'] ?? CheckResult::OK);
                if ($status === CheckResult::OK) {
                    $ok++;
                    continue;
                }
                $key = $source . '/' . $line['code'];
                $cause = new Cause((string)$line['code'], $source . ' · ' . self::describe($line, true));
                $titles[$key] = $source . ' · ' . self::describe($line, false);
                if ($status === CheckResult::FAIL) {
                    $failing[$key] = $cause;
                } else {
                    $warning[$key] = $cause;
                }
            }
        }
        if (count($reports) > StatusFiles::MAX_FILES) {
            // bounded: the servers heard from last are kept
            uasort($reports, fn ($a, $b) => (int)($b['generated_at'] ?? 0) <=> (int)($a['generated_at'] ?? 0));
            $reports = array_slice($reports, 0, StatusFiles::MAX_FILES, true);
        }
        if ($reports !== $stored) {
            $this->state->set(self::ROW, $reports);
        }
        foreach ($invalid as $key => $cause) {
            $warning[$key] = $cause;
            $titles[$key] = $refused[$key];
        }
        return $this->result($failing, $warning, $titles, $ok, $servers);
    }

    /**
     * The file's status as the server's, when it changed and keeps every rule (null: unchanged).
     *
     * @param array<mixed>|null $stored
     * @return array<mixed>|null
     * @throws \InvalidArgumentException naming the rule it breaks
     */
    private function take(string $source, ?string $json, ?array $stored, int $now, int $staleAfter): ?array
    {
        if ($json === null) {
            throw new \InvalidArgumentException(sprintf('larger than %d KB', intdiv(StatusFiles::MAX_BYTES, 1024)));
        }
        $hash = sha1($json);
        if ($stored !== null && ($stored['hash'] ?? '') === $hash) {
            return null; // not changed since the last run
        }
        $report = $this->validator->validate($json, $now, $now - $staleAfter);
        if ($report['source'] !== $source) {
            throw new \InvalidArgumentException('its source is not the one in the file name');
        }
        if ($stored !== null && $report['generated_at'] <= (int)($stored['generated_at'] ?? 0)) {
            throw new \InvalidArgumentException('not newer than the status read before (an old copy?)');
        }
        return $report + ['hash' => $hash, 'read_at' => $now];
    }

    /**
     * @param array<string, Cause> $failing
     * @param array<string, Cause> $warning
     * @param array<string, string> $titles
     */
    private function result(array $failing, array $warning, array $titles, int $ok, int $servers): CheckResult
    {
        $status = $failing !== [] ? CheckResult::FAIL : ($warning !== [] ? CheckResult::WARN : CheckResult::OK);
        $cursor = $this->state->get(self::CURSOR) ?? [];
        $notices = [];
        if ($status === CheckResult::FAIL && ($cursor['status'] ?? '') === CheckResult::FAIL) {
            foreach (array_diff_key($failing, array_flip((array)($cursor['failing'] ?? []))) as $cause) {
                $notices[] = 'Also failing: ' . $cause->detail;
            }
        }
        $next = $status === CheckResult::OK && $servers === 0 ? [] : ['status' => $status,
            'failing' => array_keys($failing)];
        if ($next !== $cursor) {
            $this->state->set(self::CURSOR, $next);
        }
        if ($status === CheckResult::OK) {
            return $servers === 0 ? CheckResult::ok() : CheckResult::ok(sprintf('%d OK from %d %s', $ok, $servers,
                $servers === 1 ? 'server' : 'servers'));
        }
        // the title names what the status is about: the problems when there are any, else the warnings
        $named = $status === CheckResult::FAIL ? $failing : $warning;
        $title = count($named) === 1 ? (string)$titles[array_key_first($named)] : sprintf('%d %s on %s',
            count($named), $status === CheckResult::FAIL ? 'server problems' : 'server warnings',
            implode(', ', array_unique(array_map(fn ($key) => strstr($key, '/', true), array_keys($named)))));
        // the detail - all the hourly status and the summary show - names what is wrong: problems, then warnings
        $all = array_map(fn ($key) => $titles[$key], array_keys($failing + $warning));
        $detail = implode('; ', array_slice($all, 0, 3)) . (count($all) > 3 ? sprintf('; and %d more', count($all) - 3)
            : '');
        return new CheckResult($status, $detail, [], $notices, $title, [], array_values($failing + $warning));
    }

    /**
     * "Disk /: 97 % (only 3 GB left)", without the detail for a title
     *
     * @param array<mixed> $line
     */
    private static function describe(array $line, bool $withDetail): string
    {
        $value = $line['value'] ?? null;
        $text = (string)($line['label'] ?? $line['code']);
        if (is_int($value) || is_float($value)) {
            $text .= ': ' . rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.')
                . ((string)($line['unit'] ?? '') !== '' ? ' ' . $line['unit'] : '');
        }
        return $text . ($withDetail && (string)($line['detail'] ?? '') !== '' ? ' (' . $line['detail'] . ')' : '');
    }
}
