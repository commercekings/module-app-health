<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Log;

use CommerceKings\AppHealth\Api\Data\LogChunk;
use CommerceKings\AppHealth\Api\Data\LogEntry;
use CommerceKings\AppHealth\Api\LogParserInterface;
use CommerceKings\AppHealth\Api\LogSourceInterface;
use CommerceKings\AppHealth\Model\Config\Backend\LogFiles;
use CommerceKings\AppHealth\Model\Log\Fingerprints;
use CommerceKings\AppHealth\Model\Log\LogSourcePool;
use CommerceKings\AppHealth\Model\Log\MonologLineParser;
use CommerceKings\AppHealth\Model\Check\SystemLog;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\LogDir;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use PHPUnit\Framework\TestCase;

/** The extension path: another module's source and parser in the pool, made safe by App health's own code. */
class LogSourcePoolTest extends TestCase
{
    use LogDir;
    use Settings;

    protected function tearDown(): void
    {
        $this->removeLogDir();
    }

    /** A third party's: a JSON lines feed, its cursor a line number. */
    private function thirdParty(array $lines): array
    {
        $source = new class ($lines) implements LogSourceInterface {
            public function __construct(private array $lines)
            {
            }

            public function read(array $cursor, int $maxBytes): LogChunk
            {
                $from = (int)($cursor['line'] ?? 0);
                return new LogChunk(implode("\n", array_slice($this->lines, $from)), ['line' => count($this->lines)]);
            }
        };
        $parser = new class implements LogParserInterface {
            public function parse(string $data): array
            {
                return array_map(function (string $line) {
                    $row = json_decode($line, true);
                    return new LogEntry($row['severity'], 'erp', $row['text'], [], $row['file'] ?? null,
                        $row['line'] ?? null);
                }, array_filter(explode("\n", $data)));
            }
        };
        return ['source' => $source, 'parser' => $parser, 'check' => 'system_log'];
    }

    public function testAnotherModulesSourceFeedsTheSystemLogCheckThroughTheNormalizer(): void
    {
        $state = new RunState(new MemoryStore());
        $config = $this->config(['system_log/error_count' => '20', 'system_log/window_minutes' => '5',
            'system_log/max_read_kb' => '1024']);
        $feed = $this->thirdParty([
            json_encode(['severity' => 'CRITICAL', 'text' => 'Sync of order 1234 for jane@example.com failed',
                'file' => '/var/www/html/app/code/Erp/Sync/Model/Push.php', 'line' => 31]),
            json_encode(['severity' => 'DEBUG', 'text' => 'ignored']),
        ]);
        $check = new SystemLog($this->logReader($state, $config, ['erp_feed' => $feed]), new Fingerprints($state, $config), $config,
            $state, new Clock());
        $state->begin();
        $result = $check->run([])[0];
        $state->commit();
        $this->assertSame(1, $result->counts['critical']);
        $this->assertCount(2, $result->notices, 'the entry, then what to do');
        $this->assertMatchesRegularExpression('/^Critical: Sync of order \(\{t:\d+\}\)\n'
            . 'Where: app\/code\/Erp\/Sync\/Model\/Push.php:31$/u', $result->notices[0]);
        $this->assertStringNotContainsString('jane', json_encode($result));
        $this->assertSame(2, $state->get('cursor:log:erp_feed')['line'], 'its cursor is kept for it');
    }

    public function testInvalidItemsAreRefused(): void
    {
        $filesystem = $this->createStub(Filesystem::class);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        foreach ([
            'item "erp" must be an array of a "source"' => ['erp' => ['source' => new \stdClass(),
                'parser' => new MonologLineParser()]],
            'item "erp" must be' => ['erp' => ['source' => $this->thirdParty([])['source']]],
            '"0" is not a code' => [$this->thirdParty([])],
            '"Erp Feed" is not a code' => ['Erp Feed' => $this->thirdParty([])],
        ] as $message => $sources) {
            try {
                new LogSourcePool($this->config(), $filesystem, $scopeConfig, new MonologLineParser(), $sources);
                $this->fail('accepted: ' . $message);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testExtraFilesAreValidNamesInVarLogOnly(): void
    {
        $pool = new LogSourcePool($this->config(['system_log/extra_files' => "payment.log\n../../etc/passwd.log,"
            . ' debug.log, system.log, a/b.log, erp-sync.log, payment.log']), $this->createStub(Filesystem::class),
            $this->createStub(ScopeConfigInterface::class), new MonologLineParser());
        $this->assertSame(['file_payment', 'file_erp_sync'], array_keys($pool->forCheck('system_log')));
        $this->assertSame([], $pool->forCheck('exceptions'));
    }

    public function testALogFileFieldTakesOneFile(): void
    {
        $backend = (new \ReflectionClass(\CommerceKings\AppHealth\Model\Config\Backend\LogFile::class))
            ->newInstanceWithoutConstructor();
        foreach (['a.log, b.log', "a.log\nb.log", 'a.log b.log', '../x.log'] as $bad) {
            $backend->setValue($bad);
            try {
                $backend->beforeSave();
                $this->fail('saved ' . $bad);
            } catch (LocalizedException $e) {
                $this->assertMatchesRegularExpression('/one log file|is not a log file name/', $e->getMessage());
            }
        }
    }

    public function testTheAdminCannotSaveAPath(): void
    {
        $backend = (new \ReflectionClass(LogFiles::class))->newInstanceWithoutConstructor();
        foreach (['../x.log', '/var/log/x.log', 'debug.log', 'x.txt'] as $bad) {
            $backend->setValue($bad);
            try {
                $backend->beforeSave();
                $this->fail('saved ' . $bad);
            } catch (LocalizedException $e) {
                $this->assertStringContainsString('is not a log file name', $e->getMessage());
            }
        }
    }
}
