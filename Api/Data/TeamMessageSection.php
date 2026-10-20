<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * A part of a team message, in this order: an optional heading (strong: a section title, e.g. "Needs attention"; else
 * a plain lead-in, e.g. "New errors this hour: 12"), tables, then lines - as a list ($bullets) or as text, one fact per
 * line ($spaced: a blank line between them, for facts of several lines). Plain text: no markup in any of it.
 *
 * @api
 */
class TeamMessageSection
{
    /**
     * @param string[] $lines
     * @param TeamMessageTable[] $tables
     */
    public function __construct(
        public readonly array $lines = [],
        public readonly ?string $heading = null,
        public readonly bool $strongHeading = false,
        public readonly bool $bullets = false,
        public readonly bool $spaced = false,
        public readonly array $tables = []
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->heading === null && $this->lines === [] && $this->tables === [];
    }
}
