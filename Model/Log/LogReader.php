<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\RunState;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * A check's new log entries, made safe: each of its sources (LogSourcePool) is read once per run from its stored
 * cursor ("cursor:log:<code>"), at most the check's "max_read_kb", parsed by the source's parser and passed through
 * the Normalizer - so only what the Normalizer returns leaves this class, whoever wrote the source and the parser.
 */
class LogReader
{
    public function __construct(
        private readonly LogSourcePool $pool,
        private readonly Normalizer $normalizer,
        private readonly RunState $state,
        private readonly Config $config,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * @return array{entries: list<array<string, mixed>>, partial: bool} Normalizer::safe() entries of all the
     *     check's sources, and whether any source had more waiting than it could read
     */
    public function entries(string $check): array
    {
        $result = ['entries' => [], 'partial' => false];
        $cap = $this->config->getInt($check, 'max_read_kb', 1024) * 1024;
        foreach ($this->pool->forCheck($check) as $code => $item) {
            $read = $this->state->memo(self::class . '|' . $code, fn () => $this->read($code, $item, $cap));
            array_push($result['entries'], ...$read['entries']);
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
        $cursor = array_filter((array)($this->state->get($key) ?? []), $scalar);
        $chunk = $item['source']->read($cursor, $cap);
        $next = array_filter($chunk->cursor, $scalar);
        if ($next !== $cursor || $this->state->get($key) === null) {
            $this->state->set($key, $next);
        }
        if ($chunk->data === '') {
            return ['entries' => [], 'partial' => $chunk->partial];
        }
        $root = $this->directoryList->getRoot();
        $withMessage = $this->config->isLogMessageIncluded();
        $entries = [];
        foreach ($item['parser']->parse($chunk->data) as $entry) {
            $safe = $this->normalizer->safe($entry, $root, $withMessage);
            if ($safe !== null) {
                $entries[] = $safe;
            }
        }
        return ['entries' => $entries, 'partial' => $chunk->partial];
    }
}
