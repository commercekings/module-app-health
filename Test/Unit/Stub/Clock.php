<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use Magento\Framework\Stdlib\DateTime\DateTime;

/** Magento's DateTime at a time the test sets. */
class Clock extends DateTime
{
    public function __construct(public int $now = 1792483200)
    {
    }

    public function gmtTimestamp($input = null)
    {
        return $this->now;
    }
}
