<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Config;

use CommerceKings\AppHealth\Model\Config\Backend\WebsiteChannels;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use CommerceKings\Notification\Api\RecipientNormalizerInterface;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

/** *Website channels* on save: a website once, a channel and a recipient each, the recipient normalized. */
class WebsiteChannelsTest extends TestCase
{
    use Settings;

    private function backend(array $rows): WebsiteChannels
    {
        $websites = [];
        foreach ([1 => 'EU Store', 2 => 'US Store'] as $id => $name) {
            $website = $this->createStub(Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getName')->willReturn($name);
            $websites[] = $website;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getWebsites')->willReturn($websites);
        $slack = $this->createStub(RecipientNormalizerInterface::class);
        $slack->method('normalize')->willReturnCallback(fn ($recipient) => preg_match('/^[CG][A-Z0-9]{8,}$/',
            strtoupper(trim($recipient))) ? strtoupper(trim($recipient)) : '');
        $backend = (new \ReflectionClass(WebsiteChannels::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(WebsiteChannels::class, 'storeManager'))->setValue($backend, $manager);
        (new \ReflectionProperty(WebsiteChannels::class, 'normalizers'))->setValue($backend,
            new RecipientNormalizerPool(['slack' => $slack]));
        return $backend;
    }

    /** @return string the error, '' when valid */
    private function save(array $rows): string
    {
        try {
            $this->backend($rows)->clean($rows);
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }
        return '';
    }

    public function testValidRowsAreNormalized(): void
    {
        $this->assertSame(['_1' => ['website_id' => '1', 'channel' => 'slack', 'recipient' => 'C0123456789']],
            $this->backend([])->clean(['_1' => ['website_id' => '1', 'channel' => 'slack',
                'recipient' => ' c0123456789 '], '__empty' => '']), 'the empty template row left out');
    }

    public function testInvalidRowsAreRefused(): void
    {
        $this->assertStringContainsString('choose a website', $this->save([['website_id' => '9', 'channel' => 'slack',
            'recipient' => 'C0123456789']]));
        $this->assertStringContainsString('"US Store" is there twice', $this->save([
            ['website_id' => '2', 'channel' => 'slack', 'recipient' => 'C0123456789'],
            ['website_id' => '2', 'channel' => 'slack', 'recipient' => 'C9876543210']]));
        $this->assertStringContainsString('needs a channel code', $this->save([['website_id' => '1', 'channel' => '',
            'recipient' => 'C0123456789']]));
        $this->assertStringContainsString('needs a channel code', $this->save([['website_id' => '1',
            'channel' => 'slack', 'recipient' => ' ']]));
        $this->assertStringContainsString('"#general" is not a recipient the "slack" channel takes',
            $this->save([['website_id' => '1', 'channel' => 'slack', 'recipient' => '#general']]));
        $this->assertSame('', $this->save([]));
    }

    public function testTheConfigReadsTheRows(): void
    {
        $config = $this->config(['general/website_channels' => json_encode([
            '_1' => ['website_id' => '2', 'channel' => 'slack', 'recipient' => 'C02'],
            '_2' => ['website_id' => '2', 'channel' => 'slack', 'recipient' => 'C-second'],
            '_3' => ['website_id' => '0', 'channel' => 'slack', 'recipient' => 'C00']])]);
        $this->assertSame([2 => ['channel' => 'slack', 'recipient' => 'C02']], $config->getWebsiteChannels(),
            'a website once; the default scope is not a website');
    }
}
