<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/** *Errors at once from*: var/log file names checked like *Extra log files*, no default (nothing at once). */
class LogFieldsWiringTest extends TestCase
{
    public function testErrorsAtOnceFromIsAListOfLogFiles(): void
    {
        $root = dirname(__DIR__, 3) . '/etc/';
        $field = simplexml_load_file($root . 'adminhtml/system.xml')
            ->xpath('//group[@id="system_log"]/field[@id="immediate_files"]')[0] ?? null;
        $this->assertNotNull($field);
        $this->assertSame(['textarea', 'Errors at once from', 'CommerceKings\AppHealth\Model\Config\Backend\LogFiles'],
            [(string)$field['type'], (string)$field->label, (string)$field->backend_model]);
        $this->assertSame([], simplexml_load_file($root . 'config.xml')
            ->xpath('//commercekings_app_health/system_log/immediate_files'), 'no default');
    }
}
