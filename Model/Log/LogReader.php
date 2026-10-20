<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * A check's new log entries, made safe: each of its sources (LogSourcePool) is read once per run from its stored
 * cursor ("cursor:log:<code>"), at most the check's "max_read_kb", parsed by the source's parser and passed through
 * the Normalizer - so only what the Normalizer returns leaves this class, whoever wrote the source and the parser.
 *
 * Entries dated more than OLD_SECONDS before the previous read are left out: a log cut back in place (or rotated
 * into a file that still holds old lines) is read from its start, and its old lines are not news.
 */
class LogReader
{
    public const OLD_SECONDS = 300;
    /** The previous read's time, kept next to the source's own cursor (never handed to the source). */
    private const READ_AT = '_read_at';

    public function __construct(
        private readonly LogSourcePool $pool,
        private readonly Normalizer $normalizer,
        private readonly RunState $state,
        private readonly Config $config,
        private readonly DirectoryList $directoryList,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, partial: bool, sensitive: list<array<string, mixed>>}
     *     Normalizer::safe() entries of all the check's sources at WARNING and above, whether any source had more
     *     waiting than it could read, and the entries of any level with sensitive data found ("source": its name)
     */
    public function entries(string $check): array
    {
        $result = ['entries' => [], 'partial' => false, 'sensitive' => []];
        $cap = $this->config->getInt($check, 'max_read_kb', 1024) * 1024;
        foreach ($this->pool->forCheck($check) as $code => $item) {
            $read = $this->state->memo(self::class . '|' . $code, fn () => $this->read($code, $item, $cap));
            array_push($result['entries'], ...$read['entries']);
            array_push($result['sensitive'], ...$read['sensitive']);
            $result['partial'] = $result['partial'] || $read['partial'];
        }
        return $result;
    }

    /**
     * @param array{source: \CommerceKings\AppHealth\Api\LogSourceInterface,
     *     parser: \CommerceKings\AppHealth\Api\LogParserInterface} $item
     * @return array{entries: list<array<string, mixed>>, partial: bool}
     */
    private function read(string $code, array $item, int $cap): array
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
            return ['entries' => [], 'partial' => $chunk->partial, 'sensitive' => []];
        }
        $source = $item['source'] instanceof FileLogSource ? $item['source']->getFile() : $code;
        $root = $this->directoryList->getRoot();
        $withMessage = $this->config->isLogMessageIncluded();
        $entries = [];
        $sensitive = [];
        foreach ($item['parser']->parse($chunk->data) as $entry) {
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
        return ['entries' => $entries, 'partial' => $chunk->partial, 'sensitive' => $sensitive];
    }
}
