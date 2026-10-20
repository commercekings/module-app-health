<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Check;

use CommerceKings\AppHealth\Api\Data\CheckResult;
use CommerceKings\AppHealth\Model\Check\EmailDisabled;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class EmailDisabledTest extends TestCase
{
    use \CommerceKings\AppHealth\Test\Unit\Stub\Db;

    /**
     * Store views 1 and 2 on website 1, 3 on website 2.
     *
     * @param int[] $disabled store ids with emails disabled (whatever the scope it was set in)
     * @return array<int, CheckResult>
     */
    private function check(array $disabled, array $websiteIds = [1, 2]): array
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnCallback(function ($path, $scope, $id) use ($disabled) {
            $this->assertSame(['system/smtp/disable', 'store'], [$path, $scope]);
            return in_array($id, $disabled, true);
        });
        $stores = [];
        foreach ([1 => 1, 2 => 1, 3 => 2] as $id => $websiteId) {
            $store = $this->createStub(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[$id] = $store;
        }
        $manager = $this->createStub(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn($stores);
        $db = $this->db(['fetchAll' => [[['scope' => 'stores', 'scope_id' => '2'], ['scope' => 'websites',
            'scope_id' => '1']]]]);
        return (new EmailDisabled($config, $manager, $this->resource($db)))->run($websiteIds);
    }

    public function testEachWebsiteForItsOwnStoreViews(): void
    {
        $this->assertSame([CheckResult::OK, CheckResult::OK], array_column($this->check([]), 'status'));
        $results = $this->check([2]);
        $this->assertSame([1, 2], array_keys($results));
        $this->assertSame(CheckResult::FAIL, $results[1]->status);
        $this->assertSame('emails are disabled in 1 of 2 store views', $results[1]->detail);
        $this->assertSame('email_disabled_setting', $results[1]->causes[0]->code);
        $this->assertSame('Disable Email Communications is set to Yes in store view 2 and website 1',
            $results[1]->causes[0]->detail, 'which scopes, from core_config_data');
        $this->assertSame(CheckResult::OK, $results[2]->status, 'another website\'s setting is not this one\'s');
        $this->assertSame([2], array_keys($this->check([3], [2])), 'only the websites asked for');
    }
}
