<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Api\LogParserInterface;
use CommerceKings\AppHealth\Api\LogSourceInterface;
use CommerceKings\AppHealth\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;

/**
 * The log sources and their parsers, by the check that reads them (di.xml argument "sources": name =>
 * ["source" => LogSourceInterface, "parser" => LogParserInterface, "check" => "system_log" or "exceptions"]).
 * Another module adds its own item; the system log check also reads the admin's extra var/log files (Monolog format).
 *
 * @api the "sources" di.xml argument is a stable extension point
 */
class LogSourcePool
{
    /** @var array<string, array{source: LogSourceInterface, parser: LogParserInterface, check: string}> by code */
    private readonly array $sources;

    /**
     * @param array<mixed> $sources
     * @throws \InvalidArgumentException for an item without a source, a parser or a check, or a repeated code
     */
    public function __construct(
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly MonologLineParser $monolog,
        array $sources = []
    ) {
        $byCode = [];
        foreach ($sources as $item) {
            $source = $item['source'] ?? null;
            $parser = $item['parser'] ?? null;
            $check = $item['check'] ?? 'system_log';
            if (!$source instanceof LogSourceInterface || !$parser instanceof LogParserInterface || !is_string($check)
                || !preg_match('/^[a-z0-9_]{1,32}$/D', $source->getCode()) || isset($byCode[$source->getCode()])) {
                throw new \InvalidArgumentException('A log source needs a LogSourceInterface with a unique code,'
                    . ' a LogParserInterface and a check code.');
            }
            $byCode[$source->getCode()] = ['source' => $source, 'parser' => $parser, 'check' => $check];
        }
        $this->sources = $byCode;
    }

    /** @return array<string, array{source: LogSourceInterface, parser: LogParserInterface, check: string}> */
    public function forCheck(string $check): array
    {
        $sources = array_filter($this->sources, fn ($item) => $item['check'] === $check);
        if ($check === 'system_log') {
            foreach ($this->config->getExtraLogFiles() as $file) {
                $code = substr('file_' . preg_replace('/[^a-z0-9]+/', '_', strtolower(substr($file, 0, -4))), 0, 32);
                $sources[$code] ??= ['source' => new FileLogSource($this->filesystem, $this->scopeConfig, $code, $file),
                    'parser' => $this->monolog, 'check' => $check];
            }
        }
        return $sources;
    }
}
