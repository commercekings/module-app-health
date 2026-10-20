<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\External;

use CommerceKings\AppHealth\Model\External\ReportValidator;
use CommerceKings\AppHealth\Model\Log\Normalizer;
use CommerceKings\AppHealth\Model\Sensitive\Detector;
use PHPUnit\Framework\TestCase;

/** A status file's rules - every one, before anything is used - and its labels and details masked. */
class ReportValidatorTest extends TestCase
{
    private const NOW = 1792483200;

    private function validator(): ReportValidator
    {
        // masking needs no sensitive-data rules: the detector is never called
        $detector = $this->createMock(Detector::class);
        $detector->expects($this->never())->method($this->anything());
        return new ReportValidator(new Normalizer($detector));
    }

    /** @param array<mixed> $report merged over a valid report */
    private function json(array $report = [], ?array $check = null): string
    {
        $line = ($check ?? []) + ['code' => 'disk_root', 'label' => 'Disk /', 'status' => 'warn', 'value' => 87,
            'unit' => '%', 'detail' => '13 GB free'];
        return json_encode(['report' => $report + ['version' => 1, 'source' => 'host-1',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', self::NOW), 'checks' => [$line]]]);
    }

    private function refusal(string $json): ?string
    {
        try {
            $this->validator()->validate($json, self::NOW, self::NOW - 900);
            return null;
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function testAValidReportIsReturnedClean(): void
    {
        $this->assertSame(['source' => 'host-1', 'generated_at' => self::NOW, 'checks' => [['code' => 'disk_root',
            'label' => 'Disk /', 'status' => 'warn', 'value' => 87, 'unit' => '%', 'detail' => '13 GB free']]],
            $this->validator()->validate($this->json(), self::NOW, self::NOW - 900));
        $minimal = $this->validator()->validate($this->json(['checks' => [['code' => 'cron', 'status' => 'ok']]]),
            self::NOW, self::NOW - 900);
        $this->assertSame(['code' => 'cron', 'label' => 'cron', 'status' => 'ok', 'value' => null, 'unit' => '',
            'detail' => ''], $minimal['checks'][0], 'no label: the code');
    }

    public function testEveryRule(): void
    {
        $at = fn (int $offset) => gmdate('Y-m-d\TH:i:s\Z', self::NOW + $offset);
        foreach ([
            ['version must be 1', $this->json(['version' => 2])],
            ['source must be', $this->json(['source' => 'host 1'])],
            ['source must be', $this->json(['source' => str_repeat('h', 41)])],
            ['ISO-8601', $this->json(['generated_at' => '2026-10-20 14:05:00'])],
            ['ISO-8601', $this->json(['generated_at' => '2026-02-30T14:05:00Z'])],
            ['ISO-8601', $this->json(['generated_at' => '2026-10-20T14:05:00'])],
            ['in the future', $this->json(['generated_at' => $at(121)])],
            ['too old', $this->json(['generated_at' => $at(-901)])],
            ['at most 50 checks', $this->json(['checks' => array_map(fn ($i) => ['code' => 'c' . $i,
                'status' => 'ok'], range(1, 51))])],
            ['checks[0].code must be', $this->json([], ['code' => 'Disk-Root'])],
            ['checks[0].code must be', $this->json([], ['code' => str_repeat('c', 41)])],
            ['checks[0].label is longer than 60', $this->json([], ['label' => str_repeat('l', 61)])],
            ['checks[0].status must be ok, warn or fail', $this->json([], ['status' => 'critical'])],
            ['checks[0].unit must be', $this->json([], ['unit' => 'gigabytes!'])],
            ['checks[0].detail is longer than 160', $this->json([], ['detail' => str_repeat('d', 161)])],
            ['checks[1].code is used twice', $this->json(['checks' => [['code' => 'a', 'status' => 'ok'],
                ['code' => 'a', 'status' => 'ok']]])],
            ['not JSON', '{"report": '],
            ['expected {"report"', '{"version": 1}'],
            ['expected {"report"', '{"report": {}, "more": 1}'],
            ['report must be an object', '{"report": [1]}'],
            ['report has an unknown key (the 1.)', $this->json(['token' => 'secret-value'])],
            ['checks[0] has an unknown key', $this->json([], ['extra' => 1])],
            ['checks[0] must be an object', $this->json(['checks' => ['disk']])],
            ['report.version must be a whole number', $this->json(['version' => '1'])],
            ['report.checks must be a list', $this->json(['checks' => ['a' => []]])],
            ['checks[0].value must be a number', $this->json([], ['value' => '87'])],
            ['checks[0].value is out of range', $this->json([], ['value' => 1e16])],
        ] as [$expected, $json]) {
            $refusal = (string)$this->refusal($json);
            $this->assertStringContainsString($expected, $refusal, $json);
            $this->assertStringNotContainsString('secret', $refusal, 'never a value from the file');
        }
        $this->assertSame('version must be 1; source must be 1-40 characters of A-Z, a-z, 0-9, ".", "_" or "-";'
            . ' generated_at must be ISO-8601 with a time zone, e.g. 2026-10-20T14:05:00Z; and 2 more',
            $this->refusal('{"report": {"checks": [{}]}}'), 'all of them, the first three named');
        $this->assertNull($this->refusal($this->json(['generated_at' => $at(120)])), 'the clock may be 2 min ahead');
        $offset = (new \DateTimeImmutable('@' . (self::NOW - 900)))->setTimezone(new \DateTimeZone('+02:00'));
        $this->assertNull($this->refusal($this->json(['generated_at' => $offset->format('Y-m-d\TH:i:sP')])),
            'with an offset, as old as allowed');
    }

    public function testALabelIsANameAndADetailIsMasked(): void
    {
        $line = $this->validator()->validate($this->json([], ['label' => 'Container sandbox-flow-php-fpm-1',
            'detail' => 'password=hunter2 for customer Jane Doe at +381 64 123 4567, https://x.test/?t=1']),
            self::NOW, self::NOW - 900)['checks'][0];
        $this->assertSame('Container sandbox-flow-php-fpm-1', $line['label'], 'a name stays as it is');
        foreach (['hunter2', 'Jane', 'Doe', '123', 'x.test'] as $leak) {
            $this->assertStringNotContainsString($leak, $line['detail'], $leak);
        }
        foreach (['Backup of jane@example.com', 'say "hi"', "it's", 'a;b', '<b>'] as $label) {
            $this->assertStringContainsString('checks[0].label may only have letters, digits',
                (string)$this->refusal($this->json([], ['label' => $label])), $label);
        }
        foreach (['Disk /', 'Certificate shop.example.com', 'Load per CPU', 'Disk /var (data)', 'Memory 85%'] as $label) {
            $this->assertNull($this->refusal($this->json([], ['label' => $label])), $label);
        }
    }

    public function testAContainersMetricsAreNumbersWithinBounds(): void
    {
        $metrics = ['cpu_percent' => 12.5, 'mem_used_bytes' => 536870912, 'mem_limit_bytes' => 2147483648,
            'net_rx_bytes' => 1200, 'net_tx_bytes' => 3400, 'blk_read_bytes' => 0, 'blk_write_bytes' => 4096,
            'restarts' => 2];
        $line = $this->validator()->validate($this->json([], ['metrics' => $metrics]), self::NOW, self::NOW - 900)
            ['checks'][0];
        $this->assertSame($metrics, $line['metrics']);
        $this->assertArrayNotHasKey('metrics', $this->validator()->validate($this->json(), self::NOW, self::NOW - 900)
            ['checks'][0], 'optional: a file without it is as valid as before');
        $this->assertArrayNotHasKey('metrics', $this->validator()->validate($this->json([], ['metrics' => []]),
            self::NOW, self::NOW - 900)['checks'][0], 'an empty object');
        $this->assertSame(450.5, $this->validator()->validate($this->json([], ['metrics' => ['cpu_percent' => 450.5]]),
            self::NOW, self::NOW - 900)['checks'][0]['metrics']['cpu_percent'], 'over 100 %: several cores');
        foreach ([
            ['metrics has an unknown key (the 2.)', ['cpu_percent' => 1, 'pids' => 3]],
            ['metrics.mem_used_bytes must be a whole number', ['mem_used_bytes' => -1]],
            ['metrics.mem_used_bytes must be a whole number', ['mem_used_bytes' => 1.5]],
            ['metrics.mem_used_bytes must be a whole number', ['mem_used_bytes' => '512M']],
            ['metrics.restarts must be a whole number', ['restarts' => 2e9]],
            ['metrics.cpu_percent must be a number from 0 to 100000', ['cpu_percent' => 100001]],
            ['metrics.cpu_percent must be a number', ['cpu_percent' => null]],
            ['checks[0].metrics must be an object', [1, 2]],
        ] as [$expected, $bad]) {
            $this->assertStringContainsString($expected, (string)$this->refusal($this->json([], ['metrics' => $bad])),
                json_encode($bad));
        }
        $this->assertStringContainsString('checks[0].metrics must be an object',
            (string)$this->refusal($this->json([], ['metrics' => 'cpu 5%'])));
    }
}
