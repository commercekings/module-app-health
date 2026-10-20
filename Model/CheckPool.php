<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model;

use CommerceKings\AppHealth\Api\CheckInterface;
use CommerceKings\AppHealth\Model\Pool\TypedPool;

/**
 * The health checks by code, in the order they run (di.xml argument "checks": code => CheckInterface; code
 * [a-z0-9_], at most 32 characters). Another module adds its own item.
 *
 * @api the "checks" di.xml argument is a stable extension point
 * @extends TypedPool<CheckInterface>
 */
class CheckPool extends TypedPool
{
    public const CODE_LENGTH = 32;

    /**
     * @param array<mixed> $checks
     * @throws \InvalidArgumentException naming a check that is not a CheckInterface, or a key that is no code
     */
    public function __construct(array $checks = [])
    {
        parent::__construct($checks, CheckInterface::class);
    }

    public function getLabel(string $code): string
    {
        return $this->get($code)?->getLabel() ?? $code;
    }
}
