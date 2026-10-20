<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Targets;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class TargetsTest extends TestCase
{
    /**
     * @param array<string, array<string, mixed>> $values scope ("default", "websites/1", "stores/1") => path => value
     */
    private function targets(array $values): Targets
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $lookup = function ($path, $scope = 'default', $id = null) use ($values) {
            $key = $scope === 'default' ? 'default' : ($scope === 'website' ? 'websites/' : 'stores/') . $id;
            // a website inherits the default
            return $values[$key][$path] ?? ($scope === 'website' ? ($values['default'][$path] ?? null) : null);
        };
        $scopeConfig->method('getValue')->willReturnCallback($lookup);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (...$args) => !empty($lookup(...$args)));
        $stores = [];
        foreach ([1 => 'Main Website Store', 2 => 'Second Store'] as $id => $frontendName) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getFrontendName')->willReturn($frontendName);
            $stores[$id] = $store;
        }
        $websites = [];
        foreach ([0, 1, 2] as $id) {
            $website = $this->createStub(Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getDefaultStore')->willReturn($stores[$id] ?? null);
            $websites[$id] = $website;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getWebsites')->willReturn([2 => $websites[2], 1 => $websites[1]]);
        $manager->method('getWebsite')->willReturnCallback(fn ($id) => $websites[$id]);
        $manager->method('getDefaultStoreView')->willReturn($stores[1]);
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Europe/Belgrade');
        return new Targets($manager, new Config($scopeConfig, $this->createStub(EncryptorInterface::class)),
            $scopeConfig, $timezone);
    }

    private const ON = ['commercekings_app_health/general/enabled' => '1',
        'commercekings_app_health/general/channel' => 'slack', 'commercekings_app_health/general/recipient' => 'C00'];

    public function testTheShopWideChecksGoToTheDefaultScopeAndWebsitesInheritIt(): void
    {
        $targets = $this->targets(['default' => self::ON,
            'websites/2' => ['commercekings_app_health/general/recipient' => 'C02'],
            'stores/2' => ['general/store_information/name' => 'Moj Planer']]);
        $shopWide = $targets->shopWide();
        $this->assertSame([0, 1, 'C00', 'Main Website Store', 'Europe/Belgrade'], [$shopWide->websiteId,
            $shopWide->storeId, $shopWide->recipient, $shopWide->name, $shopWide->timezone]);
        $websites = $targets->websites();
        $this->assertSame([1, 2], array_keys($websites));
        $this->assertSame(['C00', 'Main Website Store'], [$websites[1]->recipient, $websites[1]->name]);
        $this->assertSame(['C02', 'Moj Planer', 2],
            [$websites[2]->recipient, $websites[2]->name, $websites[2]->storeId],
            'its own recipient; the Store Information name first');
        $this->assertSame('C02', $targets->forWebsite(2)->recipient);
        $this->assertSame(0, $targets->forWebsite(0)->websiteId);
    }

    public function testOffOrWithoutARecipientIsNoTarget(): void
    {
        $targets = $this->targets(['default' => ['commercekings_app_health/general/enabled' => '0'] + self::ON,
            'websites/1' => ['commercekings_app_health/general/enabled' => '1'],
            'websites/2' => ['commercekings_app_health/general/enabled' => '1',
                'commercekings_app_health/general/recipient' => '']]);
        $this->assertNull($targets->shopWide());
        $this->assertSame([1], array_keys($targets->websites()));
        $this->assertNull($targets->forWebsite(2));
        $this->assertNotNull($targets->resolve(0), 'the test alert works while alerts are off');
    }
}
