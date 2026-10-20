<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\TeamMessageContent;

/**
 * Turns a team message into the text a channel's provider posts (Notification's Delivery::text()): its markup, its
 * lists, its tables. Registered in di.xml under the channel's code (Model\Message\RendererPool "renderers", e.g.
 * "slack"); a channel without one gets plain text.
 *
 * A renderer adds its channel's markup but does not escape: the message carries shop data (names, labels, log
 * errors), and the channel's provider escapes what would be markup to it before posting. For "slack" that is & and <
 * (module-notification-slack's SlackProvider does it), so a mention (<!channel>) or a link (<url|text>) in shop data
 * stays text. A provider that replaces it for "slack" MUST escape & and < the same way.
 *
 * @api
 */
interface TeamMessageRendererInterface
{
    public function render(TeamMessageContent $message): string;
}
