<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model;

use CommerceKings\AppHealth\Model\Heartbeat;
use CommerceKings\AppHealth\Test\Unit\Stub\Settings;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HeartbeatTest extends TestCase
{
    use Settings;

    /** @var string[] */
    private array $logs = [];
    /** @var array<int, mixed> */
    private array $options = [];
    private ?string $got = null;

    private function heartbeat(string $stored, int|\Throwable $answer = 200): Heartbeat
    {
        $curl = $this->createStub(Curl::class);
        $curl->method('setOptions')->willReturnCallback(function (array $options) {
            $this->options = $options + $this->options;
        });
        $curl->method('setTimeout')->willReturnCallback(function ($value) {
            $this->options['timeout'] = $value;
        });
        $curl->method('get')->willReturnCallback(function ($url) use ($answer) {
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            $this->got = $url;
        });
        $curl->method('getStatus')->willReturn(is_int($answer) ? $answer : 0);
        $factory = $this->createStub(CurlFactory::class);
        $factory->method('create')->willReturn($curl);
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->logs[] = $message;
        });
        return new Heartbeat($this->config(['general/heartbeat_url' => $stored]), $factory, $logger);
    }

    public function testOneShortGetToTheDecryptedUrl(): void
    {
        $heartbeat = $this->heartbeat('encrypted:https://hc-ping.com/secret-uuid');
        $this->assertTrue($heartbeat->isConfigured());
        $this->assertTrue($heartbeat->ping());
        $this->assertSame('https://hc-ping.com/secret-uuid', $this->got);
        $this->assertSame([CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_FOLLOWLOCATION => false, 'timeout' => 5],
            $this->options);
    }

    public function testFailuresAreOnlyLoggedWithoutTheUrl(): void
    {
        $this->assertFalse($this->heartbeat('encrypted:https://hc-ping.com/secret-uuid', 503)->ping());
        $this->assertFalse($this->heartbeat('encrypted:https://hc-ping.com/secret-uuid',
            new \RuntimeException('Could not resolve hc-ping.com/secret-uuid'))->ping());
        $this->assertSame(['CommerceKings_AppHealth: heartbeat answered HTTP 503',
            'CommerceKings_AppHealth: heartbeat not sent (RuntimeException)'], $this->logs);
    }

    public function testNoUrlOrNotHttpIsOff(): void
    {
        $this->assertFalse($this->heartbeat('')->isConfigured());
        $this->assertFalse($this->heartbeat('encrypted:file:///etc/passwd')->isConfigured());
        $this->assertFalse($this->heartbeat('encrypted:ftp://example.com')->ping());
        $this->assertNull($this->got);
    }
}
