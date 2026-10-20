<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\External;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Log\Normalizer;

/**
 * The rules a server's status file keeps (var/app-health/status-<source>.json), all of them, before its lines are
 * used: {"report": {...}} and no other key, the version, the names, at most MAX_CHECKS lines, the lengths, the types,
 * the statuses, and a generated_at that is not ahead of Magento's clock (FUTURE_SKEW) nor older than the caller
 * allows. A label is a name (LABEL: letters, digits, spaces and . _ / : ( ) % - only; e.g. "Container php-fpm-1",
 * "Disk /"), a detail is masked like a log message: only names and numbers are kept. A line may carry a container's
 * measurements ("metrics", METRICS: numbers only).
 */
class ReportValidator
{
    public const VERSION = 1;
    public const MAX_CHECKS = 50;
    /** How far generated_at may be ahead of Magento's clock, in seconds. */
    public const FUTURE_SKEW = 120;
    public const SOURCE = '/^[A-Za-z0-9._-]{1,40}$/D';
    public const CODE = '/^[a-z0-9_]{1,40}$/D';
    public const UNIT = '/^[A-Za-z%\/.°]{0,10}$/uD';
    public const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/D';
    public const LABEL_LENGTH = 60;
    /** A label: a name, never free text (no @, quotes or anything to mask). */
    public const LABEL = '/^[\p{L}\p{N} ._\/:()%#+-]*$/uD';
    /** A line's measurements: the most each may be (a container's CPU may pass 100 %: one core is 100). */
    public const METRICS = ['cpu_percent' => 100000, 'mem_used_bytes' => 1e15, 'mem_limit_bytes' => 1e15,
        'net_rx_bytes' => 1e15, 'net_tx_bytes' => 1e15, 'blk_read_bytes' => 1e15, 'blk_write_bytes' => 1e15,
        'restarts' => 1e9];
    public const DETAIL_LENGTH = 160;
    private const REPORT = ['version' => 'int', 'source' => 'string', 'generated_at' => 'string', 'checks' => 'list'];
    private const CHECK = ['code' => 'string', 'label' => 'string', 'status' => 'string', 'value' => 'number',
        'unit' => 'string', 'detail' => 'string', 'metrics' => 'object'];
    private const TYPES = ['int' => 'a whole number', 'string' => 'text', 'number' => 'a number or null',
        'list' => 'a list', 'object' => 'an object'];
    /** Errors named; the rest are counted. */
    private const ERRORS = 3;

    public function __construct(
        private readonly Normalizer $normalizer
    ) {
    }

    /**
     * @param int $oldest the oldest generated_at accepted (unix time)
     * @return array{source: string, generated_at: int, checks: list<array{code: string, label: string,
     *     status: string, value: float|int|null, unit: string, detail: string, metrics?: array<string, int|float>}>}
     * @throws \InvalidArgumentException naming what is wrong (never a value from the file)
     */
    public function validate(string $json, int $now, int $oldest): array
    {
        try {
            $data = json_decode($json, true, 6, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('not JSON');
        }
        if (!is_array($data) || array_keys($data) !== ['report'] || !is_array($data['report'])) {
            throw new \InvalidArgumentException('expected {"report": {...}}');
        }
        $errors = [];
        $report = $this->fields($data['report'], self::REPORT, 'report', $errors);
        if (($report['version'] ?? null) !== self::VERSION) {
            $errors[] = sprintf('version must be %d', self::VERSION);
        }
        $source = (string)($report['source'] ?? '');
        if (!preg_match(self::SOURCE, $source)) {
            $errors[] = 'source must be 1-40 characters of A-Z, a-z, 0-9, ".", "_" or "-"';
        }
        $generatedAt = self::time((string)($report['generated_at'] ?? ''));
        if ($generatedAt === null) {
            $errors[] = 'generated_at must be ISO-8601 with a time zone, e.g. 2026-10-20T14:05:00Z';
        } elseif ($generatedAt > $now + self::FUTURE_SKEW) {
            $errors[] = 'generated_at is in the future (check the server\'s clock)';
        } elseif ($generatedAt < $oldest) {
            $errors[] = 'generated_at is too old';
        }
        $checks = (array)($report['checks'] ?? []);
        if (count($checks) > self::MAX_CHECKS) {
            $errors[] = sprintf('at most %d checks', self::MAX_CHECKS);
            $checks = [];
        }
        $lines = [];
        foreach ($checks as $i => $check) {
            $line = $this->line(is_array($check) ? $check : null, (int)$i, $errors);
            if ($line !== null) {
                if (isset($lines[$line['code']])) {
                    $errors[] = sprintf('checks[%d].code is used twice', $i);
                }
                $lines[$line['code']] = $line;
            }
        }
        if ($errors !== []) {
            $more = count($errors) - self::ERRORS;
            throw new \InvalidArgumentException(implode('; ', array_slice($errors, 0, self::ERRORS))
                . ($more > 0 ? sprintf('; and %d more', $more) : ''));
        }
        return ['source' => $source, 'generated_at' => (int)$generatedAt, 'checks' => array_values($lines)];
    }

    /** Unix time of an ISO-8601 date and time with a time zone, else null. */
    public static function time(string $value): ?int
    {
        if (!preg_match(self::ISO_8601, $value)) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
        // "2026-02-30" or "25:00" roll over: not a date and time (the offset given is the time's zone)
        return $time->format('Y-m-d\\TH:i:s') === substr($value, 0, 19) ? $time->getTimestamp() : null;
    }

    /**
     * @param array<mixed>|null $check
     * @param string[] $errors
     * @return array{code: string, label: string, status: string, value: float|int|null, unit: string,
     *     detail: string, metrics?: array<string, int|float>}|null
     */
    private function line(?array $check, int $i, array &$errors): ?array
    {
        $before = count($errors);
        $where = 'checks[' . $i . ']';
        $check = $this->fields($check, self::CHECK, $where, $errors);
        $code = (string)($check['code'] ?? '');
        $label = (string)($check['label'] ?? '');
        $status = (string)($check['status'] ?? '');
        $value = $check['value'] ?? null;
        $unit = (string)($check['unit'] ?? '');
        $detail = (string)($check['detail'] ?? '');
        if (!preg_match(self::CODE, $code)) {
            $errors[] = $where . '.code must be 1-40 characters of a-z, 0-9 or "_"';
        }
        if (mb_strlen($label) > self::LABEL_LENGTH) {
            $errors[] = sprintf('%s.label is longer than %d characters', $where, self::LABEL_LENGTH);
        } elseif (!preg_match(self::LABEL, $label)) {
            $errors[] = $where . '.label may only have letters, digits, spaces and . _ / : ( ) % # + -';
        }
        if (!in_array($status, [CheckResult::OK, CheckResult::WARN, CheckResult::FAIL], true)) {
            $errors[] = $where . '.status must be ok, warn or fail';
        }
        if ((is_int($value) || is_float($value)) && (!is_finite((float)$value) || abs($value) > 1e15)) {
            $errors[] = $where . '.value is out of range';
        }
        if (!preg_match(self::UNIT, $unit)) {
            $errors[] = $where . '.unit must be at most 10 letters, "%", "/", "." or "°"';
        }
        if (mb_strlen($detail) > self::DETAIL_LENGTH) {
            $errors[] = sprintf('%s.detail is longer than %d characters', $where, self::DETAIL_LENGTH);
        }
        $metrics = isset($check['metrics']) ? $this->metrics($check['metrics'], $where, $errors) : null;
        if (count($errors) > $before) {
            return null;
        }
        $label = CheckResult::clean($label, self::LABEL_LENGTH);
        return ['code' => $code, 'label' => $label !== '' ? $label : $code, 'status' => $status,
            'value' => is_int($value) || is_float($value) ? $value : null, 'unit' => $unit,
            'detail' => CheckResult::clean($this->normalizer->mask($detail, false), self::DETAIL_LENGTH)]
            + ($metrics !== null && $metrics !== [] ? ['metrics' => $metrics] : []);
    }

    /**
     * A line's measurements: only METRICS' keys, each a number from 0 to its most.
     *
     * @param array<mixed> $metrics
     * @param string[] $errors
     * @return array<string, int|float>
     */
    private function metrics(array $metrics, string $where, array &$errors): array
    {
        $kept = [];
        $position = 0;
        foreach ($metrics as $key => $value) {
            $position++;
            $max = self::METRICS[$key] ?? null;
            if ($max === null) {
                $errors[] = sprintf('%s.metrics has an unknown key (the %d.)', $where, $position);
            } elseif (!(is_int($value) || is_float($value)) || !is_finite((float)$value) || $value < 0
                || $value > $max || ($key !== 'cpu_percent' && !is_int($value))) {
                $errors[] = sprintf('%s.metrics.%s must be a %s from 0 to %s', $where, $key,
                    $key === 'cpu_percent' ? 'number' : 'whole number', $key === 'cpu_percent' ? $max : 'its most');
            } else {
                $kept[$key] = $value;
            }
        }
        return $kept;
    }

    /**
     * The object's keys, when each is known and of its type; an unknown key is named by its position, never its text.
     *
     * @param array<mixed>|null $object
     * @param array<string, string> $types
     * @param string[] $errors
     * @return array<string, mixed>
     */
    private function fields(?array $object, array $types, string $where, array &$errors): array
    {
        if ($object === null || ($object !== [] && array_is_list($object))) {
            $errors[] = $where . ' must be an object';
            return [];
        }
        $fields = [];
        $position = 0;
        foreach ($object as $key => $value) {
            $position++;
            $type = $types[$key] ?? null;
            $ok = match ($type) {
                'int' => is_int($value),
                'string' => is_string($value),
                'number' => $value === null || is_int($value) || is_float($value),
                'list' => is_array($value) && array_is_list($value),
                // {} decodes to [], so an empty object is an empty array
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                default => false,
            };
            if ($type === null) {
                $errors[] = sprintf('%s has an unknown key (the %d.)', $where, $position);
            } elseif (!$ok) {
                $errors[] = sprintf('%s.%s must be %s', $where, $key, self::TYPES[$type]);
            } else {
                $fields[$key] = $value;
            }
        }
        return $fields;
    }
}
