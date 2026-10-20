<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

/**
 * Confirms what a sensitive-data rule's pattern found (e.g. a card number's Luhn check), registered in di.xml
 * (Model\Sensitive\ValidatorPool "validators": code => implementation) and used by its code from a rule set
 * (etc/app_health_rules.xml: validator="luhn"). A rule of type="validator" uses getPattern() as its pattern.
 * isValid() gets one match and only answers yes or no - the match is never stored, logged or sent.
 *
 * @api
 */
interface SensitiveDataValidatorInterface
{
    /** Unique, [a-z0-9_], e.g. "luhn". */
    public function getCode(): string;

    /** The candidates to check, a PCRE pattern with delimiters, for rules without a pattern of their own. */
    public function getPattern(): string;

    public function isValid(string $match): bool;
}
