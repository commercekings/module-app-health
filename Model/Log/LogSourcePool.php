<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Log;

use CommerceKings\AppHealth\Api\LogParserInterface;
use CommerceKings\AppHealth\Api\LogSourceInterface;
use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Pool\TypedPool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;

/**
 * The log sources and their parsers, by code and the check that reads them (di.xml argument "sources": code =>
 * ["source" => LogSourceInterface, "parser" => LogParserInterface, "check" => "system_log" or "exceptions"]; the code
 * names the source's cursor, "cursor:log:<code>"). Another module adds its own item; the system log check also reads
 * the admin's extra var/log files (Monolog format).
 *
 * @api the "sources" di.xml argument is a stable extension point
 * @extends TypedPool<array{source: LogSourceInterface, parser: LogParserInterface, check: string}>
 */
class LogSourcePool extends TypedPool
{
    public const CODE_LENGTH = 32;

    /**
     * @param array<mixed> $sources
     * @throws \InvalidArgumentException naming an item without a source, a parser or a check, or a key that is no code
     */
    public function __construct(
        private readonly Config $config,
        private readonly Filesystem $filesystem,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly MonologLineParser $monolog,
        array $sources = []
    ) {
        parent::__construct(array_map(fn ($item) => is_array($item) ? $item + ['check' => 'system_log'] : $item,
            $sources), LogSourceInterface::class);
    }

    protected function accepts(mixed $item): bool
    {
        return is_array($item) && ($item['source'] ?? null) instanceof LogSourceInterface
            && ($item['parser'] ?? null) instanceof LogParserInterface && is_string($item['check'] ?? null);
    }

    protected function describe(): string
    {
        return 'an array of a "source" (LogSourceInterface), a "parser" (LogParserInterface) and a "check" (a code)';
    }

    /** @return array<string, array{source: LogSourceInterface, parser: LogParserInterface, check: string}> */
    public function forCheck(string $check): array
    {
        $sources = array_filter($this->getAll(), fn ($item) => $item['check'] === $check);
        if ($check === 'system_log') {
            foreach ($this->config->getExtraLogFiles() as $file) {
                $code = substr('file_' . preg_replace('/[^a-z0-9]+/', '_', strtolower(substr($file, 0, -4))), 0, 32);
                $sources[$code] ??= ['source' => new FileLogSource($this->filesystem, $this->scopeConfig, $file),
                    'parser' => $this->monolog, 'check' => $check];
            }
        }
        return $sources;
    }
}
