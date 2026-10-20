<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\External;
use CommerceKings\AppHealth\Model\External\ReportValidator;
use CommerceKings\AppHealth\Model\External\StatusFiles;
use CommerceKings\AppHealth\Model\Log\Normalizer;
use CommerceKings\AppHealth\Model\RunState;
use CommerceKings\AppHealth\Model\Sensitive\Detector;
use CommerceKings\AppHealth\Test\Unit\Stub\Clock;
use CommerceKings\AppHealth\Test\Unit\Stub\MemoryStore;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\AppHealth\Test\Unit\Stub\VarDir;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use PHPUnit\Framework\TestCase;

/**
 * The servers' status files as one check: fail lines are problems, warn lines warnings, a server without a fresh
 * status a problem, a file that breaks a rule a warning naming it.
 */
class ExternalTest extends TestCase
{
    use Settings;
    use VarDir;

    private MemoryStore $store;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->store = new MemoryStore();
        $this->clock = new Clock();
    }

    private function check(?Filesystem $filesystem = null): CheckResult
    {
        $state = new RunState($this->store);
        $state->begin();
        $result = (new External(new StatusFiles($filesystem ?? $this->filesystem()),
            new ReportValidator(new Normalizer($this->createStub(Detector::class))), $this->config(), $state,
            $this->clock))->run([])[0];
        $state->commit();
        return $result;
    }

    /**
     * Writes status-<source>.json as the script would (to a temporary file, then renamed).
     *
     * @param array<string, string> $statuses code => status
     */
    private function report(string $source, array $statuses, int $ago = 0): void
    {
        $checks = [];
        foreach ($statuses as $code => $status) {
            $checks[] = ['code' => $code, 'label' => ucfirst($code), 'status' => $status, 'value' => 97.5,
                'unit' => '%', 'detail' => 'details'];
        }
        $this->write('status-' . $source . '.json', json_encode(['report' => ['version' => 1, 'source' => $source,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $this->clock->now - $ago), 'checks' => $checks]]));
    }

    private function write(string $name, string $content): void
    {
        $this->filesystem();
        if (!is_dir($this->var . '/app-health')) {
            mkdir($this->var . '/app-health');
        }
        file_put_contents($this->var . '/app-health/.' . $name . '.tmp', $content);
        rename($this->var . '/app-health/.' . $name . '.tmp', $this->var . '/app-health/' . $name);
    }

    /** @return string[] */
    private static function codes(CheckResult $result): array
    {
        return array_map(fn ($cause) => $cause->code, $result->causes);
    }

    public function testSilentUntilAServerReports(): void
    {
        $this->assertEquals(CheckResult::idle('not set up (no status file from a server yet)'), $this->check());
        $this->assertFileExists($this->var . '/app-health/status.json.dist', 'the folder and the format are there');
        $this->assertSame([], $this->store->rows, 'nothing stored');
    }

    public function testFailLinesAreProblemsAndWarnLinesWarnings(): void
    {
        $this->report('host-1', ['disk' => 'ok', 'backup' => 'warn']);
        $result = $this->check();
        $this->assertSame(CheckResult::WARN, $result->status);
        $this->assertSame('host-1 · Backup: 97.5 %', $result->title);
        $this->assertSame(['backup'], self::codes($result));
        $this->assertSame('host-1 · Backup: 97.5 % (details)', $result->causes[0]->detail);
        $this->assertSame('Did the backup job run? Its log on the server says why not.', $result->causes[0]->hint,
            'a line: its own hint');
        $this->assertSame('host-1 · Backup: 97.5 %', $result->detail, 'what the hourly status shows: the line');

        $this->report('host-2', ['disk' => 'fail', 'php' => 'fail']);
        $result = $this->check();
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('2 server problems on host-2', $result->title, 'the problems; the warning is a cause');
        $this->assertSame(['disk', 'php', 'backup'], self::codes($result), 'problems first');
        $this->assertSame('host-2 · Disk: 97.5 %; host-2 · Php: 97.5 %; host-1 · Backup: 97.5 %', $result->detail);

        $this->clock->now += 60;
        $this->report('host-1', ['disk' => 'ok', 'backup' => 'ok']);
        $this->report('host-2', ['disk' => 'ok', 'php' => 'ok']);
        $result = $this->check();
        $this->assertSame([CheckResult::OK, '4 OK from 2 servers'], [$result->status, $result->detail]);
        $this->assertSame(['host-1', 'host-2'], array_keys($this->store->row('external')), 'one row for all');
    }

    public function testAnUnchangedFileIsNotReadAgainNorWritten(): void
    {
        $this->report('host-1', ['disk' => 'ok']);
        $this->check();
        $statements = $this->store->statements;
        $this->clock->now += 300;
        $this->assertSame(CheckResult::OK, $this->check()->status);
        $this->assertSame(1, $this->store->statements - $statements, 'the run\'s load only');
    }

    public function testAServerWithoutAFreshStatusIsAProblem(): void
    {
        $this->report('host-1', ['disk' => 'fail']);
        $this->assertSame(['disk'], self::codes($this->check()));
        $this->clock->now += 14 * 60;
        $this->assertSame(['disk'], self::codes($this->check()), '14 minutes after its status');
        $this->clock->now += 60;
        $result = $this->check();
        $this->assertSame(CheckResult::FAIL, $result->status);
        $this->assertSame('No status from host-1', $result->title, 'no time in the title');
        $this->assertSame(['external_stale'], self::codes($result), 'its old lines are not news');
        $this->assertSame('No status from host-1 since {t:' . ($this->clock->now - 15 * 60) . '}',
            $result->causes[0]->detail);
        $this->assertStringStartsWith('On host-1: systemctl list-timers ck-host-status (or crontab -l)',
            $result->causes[0]->hint,
            'no status at all: the script\'s hint, not the line\'s');
    }

    public function testAStatusFileThatIsGoneIsAProblemForADay(): void
    {
        $this->report('host-1', ['disk' => 'ok']);
        $this->check();
        unlink($this->var . '/app-health/status-host-1.json');
        $result = $this->check();
        $this->assertSame(['external_stale'], self::codes($result));
        $this->assertStringEndsWith('(its status file is gone)', $result->causes[0]->detail);
        $this->clock->now += External::FORGET_SECONDS - 60;
        $this->assertSame(CheckResult::FAIL, $this->check()->status);
        $this->clock->now += 60;
        $this->assertEquals(CheckResult::idle('not set up (no status file from a server yet)'), $this->check(),
            'forgotten');
        $this->assertSame([], $this->store->row('external'));
    }

    public function testAFileThatBreaksARuleIsAWarningAndTheLastGoodStatusStays(): void
    {
        $this->report('host-1', ['disk' => 'fail']);
        $this->check();
        $this->write('status-host-1.json', '{"report": {"version": 2, "token": "s3cret"}}');
        $result = $this->check();
        $this->assertSame(CheckResult::FAIL, $result->status, 'the last good status still counts');
        $this->assertSame(['disk', 'external_invalid'], self::codes($result));
        $this->assertStringStartsWith('status-host-1.json: report has an unknown key', $result->causes[1]->detail);
        $this->assertStringNotContainsString('s3cret', $result->causes[1]->detail);
        $this->assertSame('host-1 · Disk: 97.5 %; status-host-1.json refused: report has an unknown key (the 2.)',
            $result->detail, 'the file and its rule reach the hourly status and the summary');

        $this->write('status-host-2.json', str_repeat(' ', StatusFiles::MAX_BYTES + 1));
        $this->write('status-host-3.json', '{"report": {"version": 1, "source": "host-1", "generated_at": "'
            . gmdate('Y-m-d\TH:i:s\Z', $this->clock->now) . '", "checks": []}}');
        $this->write('status-host-4.json', 'not json');
        $this->assertSame(['status-host-1.json: report has an unknown key (the 2.)',
            'status-host-2.json: larger than 32 KB',
            'status-host-3.json: its source is not the one in the file name', 'status-host-4.json: not JSON'],
            array_map(fn ($cause) => preg_replace('/;.*$/', '', $cause->detail),
                array_slice($this->check()->causes, 1)));
    }

    public function testAnOldCopyIsRefused(): void
    {
        $this->report('host-1', ['disk' => 'ok'], 30);
        $this->check();
        $this->report('host-1', ['disk' => 'fail'], 120);
        $result = $this->check();
        $this->assertSame(['external_invalid'], self::codes($result));
        $this->assertSame('status-host-1.json: not newer than the status read before (an old copy?)',
            $result->causes[0]->detail);
        $this->report('host-1', ['disk' => 'fail'], 30);
        $this->assertSame(['external_invalid'], self::codes($this->check()), 'another status of the same time');
        $this->report('host-1', ['disk' => 'fail'], 10);
        $this->assertSame(['disk'], self::codes($this->check()), 'a newer status is taken');
    }

    public function testTooManyFilesAndAnUnreadableFolderAreWarnings(): void
    {
        foreach (range(1, StatusFiles::MAX_FILES + 2) as $i) {
            $this->report(sprintf('h%02d', $i), ['disk' => 'ok']);
        }
        $result = $this->check();
        $this->assertSame([CheckResult::WARN, ['external_too_many']], [$result->status, self::codes($result)]);
        $this->assertSame('2 more status files left out (at most 20)', $result->causes[0]->detail);
        $this->assertSame('2 status files over 20', $result->detail);
        $this->assertCount(20, $this->store->row('external'));
        foreach (glob($this->var . '/app-health/status-*.json') as $file) {
            unlink($file);
        }
        $this->clock->now += 60;
        foreach (range(1, 5) as $i) {
            $this->report('new' . $i, ['disk' => 'ok']);
        }
        $this->check();
        $this->assertSame(['new1', 'new2', 'new3', 'new4', 'new5', 'h01', 'h02'],
            array_slice(array_keys($this->store->row('external')), 0, 7), 'the servers heard from last');
        $this->assertCount(20, $this->store->row('external'), 'bounded, also when servers come and go');

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willThrowException(new FileSystemException(__('denied')));
        $this->assertSame(['external_unreadable'], self::codes($this->check($filesystem)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hints')]
    public function testEachKindOfLineSaysWhatToRunOnTheServer(string $code, string $hint): void
    {
        $this->report('host-1', [$code => 'fail']);
        $this->assertSame($hint, $this->check()->causes[0]->hint);
    }

    /** @return array<string, array{string, string}> */
    public static function hints(): array
    {
        return [
            'a container' => ['container_php_fpm', 'On host-1: docker ps -a, then docker logs --tail 100 <container>.'],
            'a disk' => ['disk_root', 'On host-1: df -h, and du -sh var/log var/report pub/media - logs and old'
                . ' backups fill it most often.'],
            'memory' => ['memory', 'On host-1: top (sort by memory) or docker stats - which process takes it?'],
            'load' => ['load', 'On host-1: top or docker stats - which process takes the CPU (an indexer, a crawler'
                . ' on the site)?'],
            'a service' => ['service_nginx', 'On host-1: systemctl status <service>, then journalctl -u <service>'
                . ' -n 100.'],
            'a certificate' => ['ssl_shop_example_com', 'Renew the certificate (certbot renew), or see why it'
                . ' did not renew by itself.'],
            'anything else' => ['php', 'On host-1: what is wrong there.'],
        ];
    }

    public function testALineFailingWhileTheCheckFailsIsToldOnce(): void
    {
        $this->report('host-1', ['disk' => 'fail', 'php' => 'ok']);
        $this->assertSame([], $this->check()->notices, 'the alert tells it');
        $this->clock->now += 60;
        $this->report('host-1', ['disk' => 'fail', 'php' => 'fail']);
        $this->assertSame(['Also failing: host-1 · Php: 97.5 % (details)', 'Check: On host-1: what is wrong there.'],
            $this->check()->notices, 'a failing line: its own hint, not the no-status one');
        $this->assertSame([], $this->check()->notices, 'once');
    }
}
