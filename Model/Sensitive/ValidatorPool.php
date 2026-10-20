<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive;

use CommerceKings\AppHealth\Api\SensitiveDataValidatorInterface;

/**
 * The validators rule sets use by code (di.xml argument "validators": name => SensitiveDataValidatorInterface).
 *
 * @api the "validators" di.xml argument is a stable extension point
 */
class ValidatorPool
{
    /** @var array<string, SensitiveDataValidatorInterface> by code */
    private readonly array $validators;

    /**
     * @param array<mixed> $validators
     * @throws \InvalidArgumentException for one that is not a SensitiveDataValidatorInterface, or a repeated code
     */
    public function __construct(array $validators = [])
    {
        $byCode = [];
        foreach ($validators as $validator) {
            if (!$validator instanceof SensitiveDataValidatorInterface || isset($byCode[$validator->getCode()])) {
                throw new \InvalidArgumentException('Validators must implement SensitiveDataValidatorInterface,'
                    . ' each code once.');
            }
            $byCode[$validator->getCode()] = $validator;
        }
        $this->validators = $byCode;
    }

    public function get(string $code): ?SensitiveDataValidatorInterface
    {
        return $this->validators[$code] ?? null;
    }
}
