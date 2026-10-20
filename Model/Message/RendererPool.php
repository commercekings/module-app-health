<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Message;

use CommerceKings\AppHealth\Api\Data\TeamMessageContent;
use CommerceKings\AppHealth\Api\TeamMessageRendererInterface;
use CommerceKings\AppHealth\Model\Pool\TypedPool;

/**
 * The renderers by channel code (di.xml argument "renderers": channel => TeamMessageRendererInterface, e.g. "slack"
 * => SlackRenderer). A channel without one gets plain text (PlainTextRenderer). Another module adds the renderer for
 * its channel.
 *
 * @api the "renderers" di.xml argument is a stable extension point
 * @extends TypedPool<TeamMessageRendererInterface>
 */
class RendererPool extends TypedPool
{
    /**
     * @param array<mixed> $renderers
     * @throws \InvalidArgumentException naming one that is not a TeamMessageRendererInterface, or a key that is no code
     */
    public function __construct(
        private readonly PlainTextRenderer $plainText,
        array $renderers = []
    ) {
        parent::__construct($renderers, TeamMessageRendererInterface::class);
    }

    /** The text of a message for a channel: its renderer's, else plain text. */
    public function render(string $channel, TeamMessageContent $message): string
    {
        return ($this->get($channel) ?? $this->plainText)->render($message);
    }
}
