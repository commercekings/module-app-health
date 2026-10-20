<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive;

use CommerceKings\AppHealth\Api\SensitiveDataValidatorInterface;
use CommerceKings\AppHealth\Model\Pool\TypedPool;

/**
 * The validators rule sets use by code (di.xml argument "validators": code => SensitiveDataValidatorInterface; a rule
 * names it as validator="<code>").
 *
 * @api the "validators" di.xml argument is a stable extension point
 * @extends TypedPool<SensitiveDataValidatorInterface>
 */
class ValidatorPool extends TypedPool
{
    /**
     * @param array<mixed> $validators
     * @throws \InvalidArgumentException naming one that is not a SensitiveDataValidatorInterface, or a key that is no code
     */
    public function __construct(array $validators = [])
    {
        parent::__construct($validators, SensitiveDataValidatorInterface::class);
    }
}
