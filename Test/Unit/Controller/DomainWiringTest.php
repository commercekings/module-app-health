<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Controller;

use CommerceKings\AppHealth\Controller\Adminhtml\Domain\Accept;
use Magento\Framework\App\Action\HttpPostActionInterface;
use PHPUnit\Framework\TestCase;

/** The shop domain: stored by *Enabled*'s backend, shown and accepted (POST) on the Alerts group, told as a message. */
class DomainWiringTest extends TestCase
{
    private function xml(string $file): \SimpleXMLElement
    {
        return simplexml_load_file(dirname(__DIR__, 3) . '/etc/' . $file);
    }

    public function testTheFieldsAndTheButton(): void
    {
        $system = $this->xml('adminhtml/system.xml');
        $this->assertSame('CommerceKings\AppHealth\Model\Config\Backend\Enabled',
            (string)$system->xpath('//group[@id="general"]/field[@id="enabled"]/backend_model')[0]);
        $this->assertSame('CommerceKings\AppHealth\Block\Adminhtml\System\Config\Domain',
            (string)$system->xpath('//group[@id="general"]/field[@id="domain"]/frontend_model')[0]);
        $this->assertTrue(is_subclass_of(Accept::class, HttpPostActionInterface::class), 'POST, with the form key');
        $this->assertSame('CommerceKings_AppHealth::config', Accept::ADMIN_RESOURCE);
    }

    public function testTheSystemMessageIsRegisteredForTheAdminOnly(): void
    {
        $this->assertSame(['CommerceKings\AppHealth\Model\System\CopyMessage'], array_map('strval',
            $this->xml('adminhtml/di.xml')->xpath('//type[@name="Magento\Framework\Notification\MessageList"]'
                . '//item[@name="commercekings_app_health_copy"]')));
        $this->assertSame([], $this->xml('di.xml')->xpath('//type[@name="Magento\Framework\Notification\MessageList"]'),
            'not in the global di.xml: built on admin pages only');
    }
}
