<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Controller;

use CommerceKings\AppHealth\Controller\Adminhtml\Status\Send;
use Magento\Framework\App\Action\HttpPostActionInterface;
use PHPUnit\Framework\TestCase;

/** "Send status now": POST only, its own ACL resource under App health's, the button field in the Alerts group. */
class StatusNowWiringTest extends TestCase
{
    private function xml(string $file): \SimpleXMLElement
    {
        return simplexml_load_file(dirname(__DIR__, 3) . '/etc/' . $file);
    }

    public function testPostOnlyAndItsOwnAclResource(): void
    {
        $this->assertTrue(is_subclass_of(Send::class, HttpPostActionInterface::class), 'POST (Magento checks the form key)');
        $this->assertSame('CommerceKings_AppHealth::send_status', Send::ADMIN_RESOURCE);
        $resource = $this->xml('acl.xml')->xpath('//resource[@id="CommerceKings_AppHealth::config"]'
            . '/resource[@id="CommerceKings_AppHealth::send_status"]');
        $this->assertCount(1, $resource, 'under App health\'s configuration resource');
    }

    public function testTheButtonIsAGlobalField(): void
    {
        $field = $this->xml('adminhtml/system.xml')->xpath('//field[@id="status_now"]')[0] ?? null;
        $this->assertNotNull($field);
        $this->assertSame('CommerceKings\AppHealth\Block\Adminhtml\System\Config\StatusNow',
            (string)$field->frontend_model);
        $this->assertSame(['1', '0', '0'], [(string)$field['showInDefault'], (string)$field['showInWebsite'],
            (string)$field['showInStore']]);
    }
}
