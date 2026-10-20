<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Api\Data\TeamMessageSection;
use CommerceKings\AppHealth\Model\Message\SlackRenderer;
use CommerceKings\AppHealth\Model\Messages;

/**
 * Messages as Slack text: each message rendered by SlackRenderer, the containers section ('' without one), so a test
 * asserts the exact text a Slack channel gets.
 *
 * @method string alert(...$args)
 * @method string notices(...$args)
 * @method string status(...$args)
 * @method string summary(...$args)
 * @method string test(...$args)
 */
class SlackMessages
{
    private SlackRenderer $renderer;

    public function __construct(public readonly Messages $messages)
    {
        $this->renderer = new SlackRenderer();
    }

    /** @param array<mixed> $args */
    public function __call(string $name, array $args): string
    {
        return $this->renderer->render($this->messages->$name(...$args));
    }

    /** @param array<mixed> $args */
    public function containers(...$args): string
    {
        $section = $this->messages->containers(...$args);
        return $section instanceof TeamMessageSection ? $this->renderer->section($section) : '';
    }
}
