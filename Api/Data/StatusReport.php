<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * What an hourly status would say, for Api\StatusPolicyInterface::shouldPost(): the scope it goes to (0 = the
 * default scope, else a website id), the time in that scope's time zone, the checks failing and warning (labels;
 * warnings with their detail), the ERROR log entries since the last status, and when a status was last posted and
 * last posted as "All checks OK" (unix times, null: never).
 *
 * @api
 */
class StatusReport
{
    /**
     * @param string[] $problems the labels of the checks failing
     * @param array<string, string> $warnings label => detail of the checks warning
     * @param list<array{label: string, count: int, new: bool}> $errors the most frequent errors since the last status
     * @param string[] $known the labels of checks still not OK that a posted status told before (not in $problems
     *     or $warnings: those are the new ones)
     */
    public function __construct(
        public readonly int $scopeId,
        public readonly \DateTimeImmutable $now,
        public readonly array $problems,
        public readonly array $warnings,
        public readonly int $errorCount,
        public readonly array $errors,
        public readonly ?int $lastPostedAt,
        public readonly ?int $lastOkPostedAt,
        public readonly array $known = []
    ) {
    }

    /** Nothing failing, nothing warning (new or told before), no new errors. */
    public function isAllOk(): bool
    {
        return $this->problems === [] && $this->warnings === [] && $this->known === [] && $this->errorCount === 0;
    }

    /** Something the last posted status did not say: a new problem or warning, or new errors. */
    public function hasNews(): bool
    {
        return $this->problems !== [] || $this->warnings !== [] || $this->errorCount > 0;
    }
}
