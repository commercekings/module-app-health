<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\Config;
use CommerceKings\AppHealth\Model\Targets;
use Magento\Framework\App\Config\ScopeConfigInterface;
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
            return $values[$key][$path] ?? ($scope !== 'default' ? ($values['default'][$path] ?? null) : null);
        };
        $scopeConfig->method('getValue')->willReturnCallback($lookup);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (...$args) => !empty($lookup(...$args)));
        $stores = [];
        foreach ([1 => 'Main Website Store', 2 => 'Second Store'] as $id => $frontendName) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getFrontendName')->willReturn($frontendName);
            $store->method('getBaseUrl')->willReturn('https://' . ($id === 1 ? 'shop.example.com' : 'MOJ.example.rs')
                . '/');
            $stores[$id] = $store;
        }
        $websites = [];
        foreach ([0, 1, 2] as $id) {
            $website = $this->createStub(Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getName')->willReturn(['Admin', 'Main Website', 'Second Website'][$id]);
            $website->method('getDefaultStore')->willReturn($stores[$id] ?? null);
            $websites[$id] = $website;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getWebsites')->willReturn([2 => $websites[2], 1 => $websites[1]]);
        $manager->method('getWebsite')->willReturnCallback(fn ($id) => $websites[$id]);
        $manager->method('getDefaultStoreView')->willReturn($stores[1]);
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Europe/Belgrade');
        return new Targets($manager, new Config($scopeConfig),
            $scopeConfig, $timezone);
    }

    private const ON = ['commercekings_app_health/general/enabled' => '1',
        'commercekings_app_health/general/channel' => 'slack', 'commercekings_app_health/general/recipient' => 'C00'];

    public function testGlobalSettingsAndAWebsiteChannelOfItsOwn(): void
    {
        $targets = $this->targets(['default' => self::ON + ['commercekings_app_health/general/website_channels'
            => json_encode(['_1' => ['website_id' => '2', 'channel' => 'slack', 'recipient' => 'C02']])],
            'websites/2' => ['commercekings_app_health/general/recipient' => 'C-ignored'],
            'stores/2' => ['general/store_information/name' => 'Moj Planer']]);
        $shopWide = $targets->shopWide();
        $this->assertSame([0, 1, 'C00', '', 'Europe/Belgrade'], [$shopWide->websiteId,
            $shopWide->storeId, $shopWide->recipient, $shopWide->name, $shopWide->timezone],
            'no Store Information name at the default scope: not the default store\'s - the header says the domain');
        $websites = $targets->websites();
        $this->assertSame([1, 2], array_keys($websites));
        $this->assertSame(['C00', 'Main Website Store', false], [$websites[1]->recipient, $websites[1]->name,
            $websites[1]->ownChannel], 'no row: the global channel');
        $this->assertSame(['shop.example.com', 'moj.example.rs'], [$shopWide->domain, $websites[2]->domain],
            'the host of its store view\'s secure base URL, for the header');
        $this->assertSame(['C02', 'Moj Planer', 2, true],
            [$websites[2]->recipient, $websites[2]->name, $websites[2]->storeId, $websites[2]->ownChannel],
            'its row; a website-scope setting is not read; the Store Information name first');
        $this->assertSame('C02', $targets->forWebsite(2)->recipient);
        $this->assertSame(0, $targets->forWebsite(0)->websiteId);
    }

    public function testTheGlobalNameIsTheShopsNotAWebsitesStoreName(): void
    {
        $named = $this->targets(['default' => self::ON + ['general/store_information/name' => 'Moj Planer Group'],
            'stores/1' => ['general/store_information/name' => 'Moj Planer EU']]);
        $this->assertSame(['Moj Planer Group', 'Moj Planer EU'], [$named->shopWide()->name,
            $named->websites()[1]->name], 'global: the default scope\'s Store Information name');
        $this->assertSame('', $this->targets(['default' => self::ON])->shopWide()->name,
            'none set: no store\'s name for the whole shop - the header says the domain');
    }

    public function testWebsitesWithTheSameStoreNameAreToldApart(): void
    {
        $targets = $this->targets(['default' => self::ON + ['general/store_information/name' => 'Moj Planer']]);
        $websites = $targets->websites();
        $this->assertSame(['Moj Planer (Main Website)', 'Moj Planer (Second Website)'],
            [$websites[1]->name, $websites[2]->name], 'both inherit the name: the website says which');
    }

    public function testOffOrWithoutARecipientIsNoTarget(): void
    {
        $targets = $this->targets(['default' => ['commercekings_app_health/general/enabled' => '0'] + self::ON,
            'websites/1' => ['commercekings_app_health/general/enabled' => '1']]);
        $this->assertNull($targets->shopWide(), 'global: a website can\'t turn it on');
        $this->assertSame([], $targets->websites());
        $this->assertNotNull($targets->global(), 'the test alert works while app health is off');
        $this->assertNull($this->targets(['default' => ['commercekings_app_health/general/recipient' => ''] + self::ON])
            ->shopWide());
    }
}
