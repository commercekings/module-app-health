<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Api\WebsiteCheckInterface;

/**
 * The health checks, in the order they run (di.xml argument "checks": name => CheckInterface for a shop-wide check,
 * WebsiteCheckInterface for a check per website). Another module adds its own item.
 *
 * @api the "checks" di.xml argument is a stable extension point
 */
class CheckPool
{
    /** @var array<string, CheckInterface|WebsiteCheckInterface> by code */
    private readonly array $checks;

    /**
     * @param array<mixed> $checks CheckInterface or WebsiteCheckInterface
     * @throws \InvalidArgumentException for a check that is neither interface, an invalid or a repeated code
     */
    public function __construct(array $checks = [])
    {
        $byCode = [];
        foreach ($checks as $check) {
            if (!$check instanceof CheckInterface && !$check instanceof WebsiteCheckInterface) {
                throw new \InvalidArgumentException(
                    'Health checks must implement CheckInterface or WebsiteCheckInterface.'
                );
            }
            $code = $check->getCode();
            if (!preg_match('/^[a-z0-9_]{1,32}$/D', $code) || isset($byCode[$code])) {
                throw new \InvalidArgumentException(sprintf('Health check code "%s" is invalid or used twice.', $code));
            }
            $byCode[$code] = $check;
        }
        $this->checks = $byCode;
    }

    /** @return array<string, CheckInterface|WebsiteCheckInterface> by code */
    public function getAll(): array
    {
        return $this->checks;
    }

    /** @return array<string, CheckInterface> the shop-wide checks, by code */
    public function getShopWide(): array
    {
        return array_filter($this->checks, fn ($check) => $check instanceof CheckInterface);
    }

    /** @return array<string, WebsiteCheckInterface> the checks per website, by code */
    public function getPerWebsite(): array
    {
        return array_filter($this->checks, fn ($check) => $check instanceof WebsiteCheckInterface);
    }

    public function getLabel(string $code): string
    {
        return isset($this->checks[$code]) ? $this->checks[$code]->getLabel() : $code;
    }
}
