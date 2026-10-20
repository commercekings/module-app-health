<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\App\Filesystem\DirectoryList;
use CommerceKings\AppHealth\Model\Sensitive\Detector;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * A check's new log entries, made safe: each of its sources (LogSourcePool) is read once per run from its stored
 * cursor ("cursor:log:<code>"), at most the check's "max_read_kb", parsed by the source's parser and passed through
 * the Normalizer - so only what the Normalizer returns leaves this class, whoever wrote the source and the parser.
 *
 * Each check's reading has a time budget (BUDGET_SECONDS, sources and sensitive-data scan together): when it is
 * used up the rest of what was read is not scanned, and the result is "partial" like a backlog over the cap. A
 * sensitive-data rule that hit the backtracking limit is logged by its key (once per source and run).
 *
 * Entries dated more than OLD_SECONDS before the previous read are left out: a log cut back in place (or rotated
 * into a file that still holds old lines) is read from its start, and its old lines are not news.
 */
class LogReader
{
    public const OLD_SECONDS = 300;
    public const BUDGET_SECONDS = 1.0;
    /** The previous read's time, kept next to the source's own cursor (never handed to the source). */
    private const READ_AT = '_read_at';

    public function __construct(
        private readonly LogSourcePool $pool,
        private readonly Normalizer $normalizer,
        private readonly RunState $state,
        private readonly Config $config,
        private readonly DirectoryList $directoryList,
        private readonly DateTime $dateTime,
        private readonly Detector $detector,
        private readonly LoggerInterface $logger,
        private readonly float $budgetSeconds = self::BUDGET_SECONDS
    ) {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, partial: bool, skipped: int,
     *     sensitive: list<array<string, mixed>>} Normalizer::safe() entries of all the check's sources at WARNING
     *     and above, whether any source had more waiting than it could read (or scan in time), the bytes left out,
     *     and the entries of any level with sensitive data found ("source": its name)
     */
    public function entries(string $check): array
    {
        return $this->state->memo(self::class . '|' . $check, function () use ($check): array {
            $result = ['entries' => [], 'partial' => false, 'skipped' => 0, 'sensitive' => []];
            $cap = $this->config->getInt($check, 'max_read_kb', 1024) * 1024;
            $deadline = microtime(true) + $this->budgetSeconds;
            foreach ($this->pool->forCheck($check) as $code => $item) {
                $read = $this->read($code, $item, $cap, $deadline);
                array_push($result['entries'], ...$read['entries']);
                array_push($result['sensitive'], ...$read['sensitive']);
                $result['partial'] = $result['partial'] || $read['partial'];
                $result['skipped'] += $read['skipped'];
            }
            return $result;
        });
    }

    /**
     * @param array{source: \CommerceKings\AppHealth\Api\LogSourceInterface,
     *     parser: \CommerceKings\AppHealth\Api\LogParserInterface} $item
     * @return array{entries: list<array<string, mixed>>, partial: bool, skipped: int,
     *     sensitive: list<array<string, mixed>>}
     */
    private function read(string $code, array $item, int $cap, float $deadline): array
    {
        $key = 'cursor:log:' . $code;
        $scalar = fn ($value) => is_int($value) || is_string($value);
        $stored = (array)($this->state->get($key) ?? []);
        $readAt = (int)($stored[self::READ_AT] ?? 0);
        unset($stored[self::READ_AT]);
        $cursor = array_filter($stored, $scalar);
        $chunk = $item['source']->read($cursor, $cap);
        $now = $this->dateTime->gmtTimestamp();
        $next = array_filter($chunk->cursor, $scalar);
        unset($next[self::READ_AT]);
        if ($next !== $cursor || $this->state->get($key) === null || $chunk->data !== '') {
            $this->state->set($key, $next + [self::READ_AT => $now]);
        }
        if ($chunk->data === '') {
            return ['entries' => [], 'partial' => $chunk->partial, 'skipped' => $chunk->skippedBytes,
                'sensitive' => []];
        }
        $source = $item['source'] instanceof FileLogSource ? $item['source']->getFile() : $code;
        $root = $this->directoryList->getRoot();
        $withMessage = $this->config->isLogMessageIncluded();
        $entries = [];
        $sensitive = [];
        $partial = $chunk->partial;
        $outOfTime = false;
        $this->detector->until($deadline);
        $this->detector->takeCut(); // the timings of this source only
        foreach ($item['parser']->parse($chunk->data) as $entry) {
            if (microtime(true) > $deadline) {
                $outOfTime = true; // the rest is not scanned (its bytes are read, the cursor moves on)
                break;
            }
            if ($readAt > 0 && $entry->time !== null && $entry->time < $readAt - self::OLD_SECONDS) {
                continue; // read before, or an old line of a log cut back in place
            }
            $safe = $this->normalizer->safe($entry, $root, $withMessage);
            if ($safe === null) {
                continue;
            }
            if ($safe['sensitive'] !== []) {
                $sensitive[] = $safe + ['source' => $source];
            }
            if ($safe['rank'] > 0) {
                $entries[] = $safe;
            }
        }
        $outOfTime = $outOfTime || $this->detector->wasCut();
        $this->detector->until(null);
        $cut = $this->detector->takeCut();
        if ($outOfTime) {
            $partial = true;
            $this->logger->warning(sprintf('CommerceKings_AppHealth: the scan of %s stopped at its time budget (%d ms)'
                . '%s; the slowest sensitive-data rules: %s', $source, (int)($this->budgetSeconds * 1000),
                $cut['rule'] !== null ? ' during rule ' . $cut['rule'] : '', implode(', ', array_map(fn ($rule, $ms)
                    => $rule . ' ' . $ms . ' ms', array_keys($cut['slowest']), $cut['slowest'])) ?: '-'));
        }
        foreach ($this->detector->takeSlowRules() as $rule => $entriesHit) {
            $this->logger->warning(sprintf('CommerceKings_AppHealth: sensitive-data rule %s hit the backtracking limit'
                . ' on %d entries of %s and found nothing there: make its pattern simpler', $rule, $entriesHit,
                $source));
        }
        return ['entries' => $entries, 'partial' => $partial, 'skipped' => $chunk->skippedBytes,
            'sensitive' => $sensitive];
    }
}
