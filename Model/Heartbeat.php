<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * One GET to the configured heartbeat URL (e.g. Healthchecks.io, Better Stack, UptimeRobot) after a complete run:
 * when the pings stop - the server, PHP, the database or cron down - the outside service alerts. Short timeouts,
 * never retried; a failure is only logged, without the URL (it is a secret).
 */
class Heartbeat
{
    public const CONNECT_TIMEOUT = 2;
    public const TIMEOUT = 5;

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->config->getHeartbeatUrl() !== '';
    }

    public function ping(): bool
    {
        $url = $this->config->getHeartbeatUrl();
        if ($url === '') {
            return false;
        }
        try {
            $curl = $this->curlFactory->create();
            $curl->setOptions([CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_FOLLOWLOCATION => false]);
            $curl->setTimeout(self::TIMEOUT);
            $curl->get($url);
            $status = $curl->getStatus();
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('CommerceKings_AppHealth: heartbeat not sent (%s)', get_class($e)));
            return false;
        }
        if ($status < 200 || $status >= 300) {
            $this->logger->warning(sprintf('CommerceKings_AppHealth: heartbeat answered HTTP %d', $status));
            return false;
        }
        return true;
    }
}
