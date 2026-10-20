<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit;

use PHPUnit\Framework\TestCase;

/** di.xml, config.xml and system.xml agree: every check has a switch, its defaults and an admin group. */
class ConfigFilesTest extends TestCase
{
    private function xml(string $file): \SimpleXMLElement
    {
        return simplexml_load_file(dirname(__DIR__, 2) . '/etc/' . $file);
    }

    public function testEveryCheckHasItsSwitchDefaultsAndAdminFields(): void
    {
        $checks = [];
        $pool = '//type[@name="CommerceKings\AppHealth\Model\CheckPool"]//item';
        foreach ($this->xml('di.xml')->xpath($pool) as $item) {
            $checks[] = (string)$item['name'];
        }
        $this->assertSame(['cron', 'indexers', 'caches', 'maintenance', 'email_disabled', 'exceptions', 'queues',
            'email_failing', 'sales_emails', 'system_log'], $checks);
        $defaults = $this->xml('config.xml')->default->commercekings_app_health;
        $system = $this->xml('adminhtml/system.xml');
        foreach ($checks as $code) {
            $this->assertNotNull($defaults->$code->enabled[0] ?? null, $code . ' has a default switch');
            $group = $system->xpath('//section[@id="commercekings_app_health"]/group[@id="' . $code . '"]')[0] ?? null;
            $this->assertNotNull($group, $code . ' has an admin group');
            foreach ($defaults->$code->children() as $field) {
                $this->assertNotEmpty($group->xpath('field[@id="' . $field->getName() . '"]'),
                    $code . '/' . $field->getName() . ' is in the admin');
            }
        }
        $this->assertSame(['0', '0'], [(string)$defaults->queues->enabled, (string)$defaults->general->enabled],
            'off by default: the broker connection, and alerts until a recipient is set');
    }

    public function testNothingOnTheStorefront(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['etc/frontend', 'etc/webapi_rest', 'etc/graphql', 'view/frontend', 'etc/events.xml',
            'etc/webapi.xml'] as $path) {
            $this->assertFileDoesNotExist($root . '/' . $path);
        }
        $this->assertStringNotContainsString('<plugin', file_get_contents($root . '/etc/di.xml'));
    }

    public function testOneCronJobEveryFiveMinutesAndTheSummaryApart(): void
    {
        $jobs = [];
        foreach ($this->xml('crontab.xml')->xpath('//job') as $job) {
            $jobs[(string)$job['name']] = (string)$job->schedule;
        }
        $this->assertSame(['commercekings_app_health_run' => '*/5 * * * *',
            'commercekings_app_health_summary' => '*/15 * * * *'], $jobs);
    }

    public function testOneTableOfOurOwn(): void
    {
        $tables = $this->xml('db_schema.xml')->xpath('//table');
        $this->assertCount(1, $tables);
        $this->assertSame('ck_app_health_state', (string)$tables[0]['name']);
    }
}
