<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api\Data;

/**
 * What App health tells a team, before it is text: a title (what and where, e.g. "Problem: Cron stopped · Acme Shop ·
 * shop.example.com"), a severity (for a renderer that colours or picks an icon), sections and what to do (hints). A
 * channel's renderer (Api\TeamMessageRendererInterface) turns it into the text its provider posts.
 *
 * @api
 */
class TeamMessageContent
{
    public const PROBLEM = 'problem';
    public const WARNING = 'warning';
    public const RESOLVED = 'resolved';
    public const NOTICE = 'notice';
    public const SECURITY = 'security';
    public const STATUS = 'status';
    public const SUMMARY = 'summary';
    public const TEST = 'test';

    /**
     * @param TeamMessageSection[] $sections in order; empty ones are not shown
     * @param string[] $hints what to do, each a line of its own
     */
    public function __construct(
        public readonly string $title,
        public readonly string $severity,
        public readonly array $sections = [],
        public readonly array $hints = []
    ) {
    }

    /** The same message with more sections after its own (e.g. the containers under a status). */
    public function withSections(TeamMessageSection ...$sections): self
    {
        return new self($this->title, $this->severity, array_merge($this->sections, $sections), $this->hints);
    }
}
