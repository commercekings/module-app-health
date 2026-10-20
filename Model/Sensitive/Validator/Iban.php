<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Sensitive\Validator;

use CommerceKings\AppHealth\Api\SensitiveDataValidatorInterface;

/** An IBAN: a country code, 2 check digits and up to 30 characters, with a valid ISO 7064 mod-97 checksum. */
class Iban implements SensitiveDataValidatorInterface
{
    public function getCode(): string
    {
        return 'iban';
    }

    public function getPattern(): string
    {
        return '/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]){11,30}\b/';
    }

    public function isValid(string $match): bool
    {
        $iban = strtoupper((string)preg_replace('/\s+/', '', $match));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
        }
        $rest = 0;
        foreach (str_split($digits, 7) as $part) {
            $rest = (int)($rest . $part) % 97;
        }
        return $rest === 1;
    }
}
