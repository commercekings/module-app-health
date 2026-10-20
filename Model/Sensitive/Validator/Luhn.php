<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive\Validator;

use CommerceKings\AppHealth\Api\SensitiveDataValidatorInterface;

/**
 * A payment card number: 13-19 digits (spaces or dashes between groups), a prefix of a card scheme (Visa, Mastercard,
 * American Express, Discover, JCB, Diners, Maestro, UnionPay) and a valid Luhn check digit. Order ids, timestamps and
 * other long numbers rarely pass all three.
 */
class Luhn implements SensitiveDataValidatorInterface
{
    /** Visa; Mastercard (51-55, 2221-2720); Amex; Discover; UnionPay; JCB; Diners; Maestro */
    private const PREFIX = '/^(?:4|5[1-5]|2(?:2[2-9][1-9]|2[3-9]|[3-6]|7[01]|720)|3[47]|6(?:011|5|4[4-9]|2)'
        . '|35(?:2[89]|[3-8])|3(?:0[0-5]|[68])|5[06-9])/';

    public function getPattern(): string
    {
        return '/(?<![\d.\/-])\d(?:[ -]?\d){12,18}(?![\d.\/-])/';
    }

    public function isValid(string $match): bool
    {
        $digits = (string)preg_replace('/\D/', '', $match);
        $length = strlen($digits);
        if ($length < 13 || $length > 19 || !preg_match(self::PREFIX, $digits) || preg_match('/^(\d)\1+$/', $digits)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < $length; $i++) {
            $digit = (int)$digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                $digit -= $digit > 9 ? 9 : 0;
            }
            $sum += $digit;
        }
        return $sum % 10 === 0;
    }
}
