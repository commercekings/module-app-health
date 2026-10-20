<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Model\Sensitive;

use CommerceKings\AppHealth\Model\Sensitive\Validator\Iban;
use CommerceKings\AppHealth\Model\Sensitive\Validator\Luhn;
use PHPUnit\Framework\TestCase;

class ValidatorsTest extends TestCase
{
    public function testCardNumbersOfKnownSchemesWithAValidCheckDigit(): void
    {
        $luhn = new Luhn();
        foreach (['4111 1111 1111 1111', '4111-1111-1111-1111', '5555555555554444', '2223003122003222',
            '378282246310005', '6011111111111117', '3530111333300000', '4000056655665556'] as $card) {
            $this->assertTrue($luhn->isValid($card), $card);
        }
    }

    public function testOrderIdsTimestampsAndOtherNumbersAreNotCards(): void
    {
        $luhn = new Luhn();
        foreach ([
            '4111111111111112' => 'a wrong check digit',
            '1792483200123' => 'a millisecond timestamp (no card prefix)',
            '000000123456789' => 'an increment id',
            '0000000000000000' => 'zeros',
            '4444444444444444' => 'one digit repeated',
            '9999999999999995' => 'no card prefix',
            '411111111111' => 'too short',
            '41111111111111111111' => 'too long',
            '20261020080102123' => 'a date and time',
        ] as $number => $why) {
            $this->assertFalse($luhn->isValid((string)$number), $why);
        }
        $this->assertSame(0, preg_match($luhn->getPattern(), 'v2.4.1111111111111111'), 'part of a version');
        $this->assertSame(1, preg_match($luhn->getPattern(), 'card 4111 1111 1111 1111 ok'));
    }

    public function testIbanChecksum(): void
    {
        $iban = new Iban();
        foreach (['DE89370400440532013000', 'GB82 WEST 1234 5698 7654 32', 'RS35260005601001611379'] as $valid) {
            $this->assertTrue($iban->isValid($valid), $valid);
        }
        foreach (['DE89370400440532013001', 'XX00ABCDEFGHIJKLMNOP', 'DE89'] as $invalid) {
            $this->assertFalse($iban->isValid($invalid), $invalid);
        }
    }
}
