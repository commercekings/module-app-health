<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\External;

use CommerceKings\AppHealth\Model\External\ReportValidator;
use CommerceKings\AppHealth\Model\Log\Normalizer;
use CommerceKings\AppHealth\Model\Sensitive\Detector;
use PHPUnit\Framework\TestCase;

/**
 * docs/examples/host-status.sh against a scripted Docker CLI (_files/host-status/docker): what it reports when Docker
 * cannot be read or a project has no container - a failure, never a healthy-looking report - and that App health takes
 * every report it writes. Linux only (it reads /proc), with bash and jq.
 *
 * phpcs:disable Magento2.Security.InsecureFunction -- it runs the shell script it tests
 */
class HostStatusScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../../docs/examples/host-status.sh';
    private const FAKE = __DIR__ . '/../../_files/host-status';

    protected function setUp(): void
    {
        foreach (['bash', 'jq', 'nproc', 'sha1sum', 'df'] as $command) {
            if (trim((string)shell_exec('command -v ' . $command)) === '') {
                $this->markTestSkipped('needs ' . $command);
            }
        }
        if (!is_readable('/proc/loadavg') || !is_readable('/proc/meminfo')) {
            $this->markTestSkipped('needs Linux (/proc)');
        }
    }

    /**
     * The report's lines by code, after App health's validator took it.
     *
     * @param array<string, string> $env
     * @return array<string, array<string, mixed>>
     */
    private function report(array $env, bool $docker = true): array
    {
        $bin = sys_get_temp_dir() . '/host-status-bin-' . getmypid();
        if (!is_dir($bin)) {
            mkdir($bin);
        }
        // the system's tools, without its docker: ours or none
        foreach (['bash', 'jq', 'awk', 'tr', 'cut', 'sort', 'grep', 'sha1sum', 'df', 'nproc', 'date', 'mktemp', 'rm',
            'cat', 'hostname'] as $tool) {
            $path = trim((string)shell_exec('command -v ' . $tool));
            if ($path !== '' && !file_exists($bin . '/' . $tool)) {
                symlink($path, $bin . '/' . $tool);
            }
        }
        if (is_link($bin . '/docker')) {
            unlink($bin . '/docker');
        }
        if ($docker) {
            symlink(realpath(self::FAKE . '/docker'), $bin . '/docker');
        }
        $process = proc_open(['bash', self::SCRIPT, '--dry-run'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            null, $env + ['PATH' => $bin, 'CK_ENV_FILE' => '/nonexistent', 'CK_SOURCE' => 'host-1']);
        $out = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($process), (string)$errors);
        $detector = $this->createStub(Detector::class);
        $validated = (new ReportValidator(new Normalizer($detector)))->validate((string)$out, time(), time() - 900);
        $lines = [];
        foreach ($validated['checks'] as $line) {
            $lines[$line['code']] = $line;
        }
        $this->assertStringNotContainsString('secret', (string)$out, 'Docker\'s own message stays out');
        return $lines;
    }

    /** @param array<string, array<string, mixed>> $lines */
    private function containerLines(array $lines): array
    {
        return array_values(array_filter(array_keys($lines), fn (string $code) => str_starts_with($code, 'container_')
            || str_starts_with($code, 'compose_') || $code === 'docker_unavailable'));
    }

    public function testDockerThatCannotBeReadIsAFailureNotAnEmptyProject(): void
    {
        foreach (['down' => 'cannot reach Docker (the socket or DOCKER_HOST)',
            'denied' => 'permission denied on the Docker socket',
            'forbidden' => 'refused by the socket proxy'] as $mode => $reason) {
            $lines = $this->report(['CK_COMPOSE_PROJECT' => 'shop', 'FAKE_DOCKER' => $mode]);
            $this->assertSame(['docker_unavailable'], $this->containerLines($lines), $mode);
            $this->assertSame('fail', $lines['docker_unavailable']['status']);
            $this->assertSame('cannot list the containers: ' . $reason, $lines['docker_unavailable']['detail']);
            $this->assertArrayHasKey('disk_root', $lines, 'the rest is still reported');
        }
        $lines = $this->report(['CK_CONTAINERS' => 'php nginx', 'FAKE_DOCKER' => 'down']);
        $this->assertSame(['docker_unavailable'], $this->containerLines($lines), 'not "not found" for each');
        $lines = $this->report(['CK_CONTAINERS' => 'php'], false);
        $this->assertSame('cannot list the containers: no docker command', $lines['docker_unavailable']['detail']);
    }

    public function testAProjectWithoutContainersFails(): void
    {
        $lines = $this->report(['CK_COMPOSE_PROJECT' => 'shop', 'FAKE_DOCKER' => 'empty']);
        $this->assertSame(['compose_shop'], $this->containerLines($lines));
        $this->assertSame(['fail', 'Compose project shop', 'no containers for project shop; check the name with docker'
            . ' compose ls'], [$lines['compose_shop']['status'], $lines['compose_shop']['label'],
            $lines['compose_shop']['detail']]);
    }

    public function testAProjectsContainersAndAListedContainerNotFound(): void
    {
        $lines = $this->report(['CK_COMPOSE_PROJECT' => 'shop',
            'FAKE_DOCKER' => "shop-php-1\trunning\tUp 2 hours (healthy)\nshop-db-1\texited\tExited (137) 3 minutes ago"]);
        $this->assertSame(['container_shop_db_1', 'container_shop_php_1'], $this->containerLines($lines));
        $this->assertSame(['ok', 'Container php-1'], [$lines['container_shop_php_1']['status'],
            $lines['container_shop_php_1']['label']]);
        $this->assertSame('exited (code 137)', $lines['container_shop_db_1']['detail']);
        $lines = $this->report(['CK_CONTAINERS' => 'php nginx', 'FAKE_DOCKER' => "php\trunning\tUp 1 hour"]);
        $this->assertSame(['container_php', 'container_nginx'], $this->containerLines($lines));
        $this->assertSame(['fail', 'not found'], [$lines['container_nginx']['status'],
            $lines['container_nginx']['detail']]);
    }

    public function testNothingToWatchNeedsNoDocker(): void
    {
        $lines = $this->report(['CK_CONTAINERS' => ' '], false); // blank: proc_open leaves an empty value out
        $this->assertSame([], $this->containerLines($lines));
        $this->assertArrayHasKey('memory', $lines);
    }
}
