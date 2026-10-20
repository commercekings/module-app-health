<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * Why a check fails, as far as Magento's own data shows it: a code (e.g. "cron_not_started"), what was found (names,
 * numbers and times only - "{t:<unix time>}" is shown in the recipient's time zone) and where to look, translated.
 * An alert shows each cause and its hint.
 *
 * @api
 */
class Cause
{
    public readonly string $detail;
    public readonly string $hint;

    /** @throws \InvalidArgumentException for a code that is not [a-z0-9_] */
    public function __construct(
        public readonly string $code,
        string $detail,
        string $hint = ''
    ) {
        if (!preg_match('/^[a-z0-9_]{1,40}$/D', $code)) {
            throw new \InvalidArgumentException('A cause code is [a-z0-9_], at most 40 characters.');
        }
        $this->detail = CheckResult::clean($detail, CheckResult::NOTICE_LENGTH);
        $this->hint = CheckResult::clean($hint, CheckResult::DETAIL_LENGTH);
    }

    /** @return array{0: string, 1: string, 2: string} as the "checks" row keeps it */
    public function toArray(): array
    {
        return [$this->code, $this->detail, $this->hint];
    }
}
