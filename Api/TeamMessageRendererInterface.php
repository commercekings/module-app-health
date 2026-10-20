<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Api;

use CommerceKings\AppHealth\Api\Data\TeamMessageContent;

/**
 * Turns a team message into the text a channel's provider posts (Notification's Delivery::text()): its markup, its
 * lists, its tables. Registered in di.xml under the channel's code (Model\Message\RendererPool "renderers", e.g.
 * "slack"); a channel without one gets plain text.
 *
 * @api
 */
interface TeamMessageRendererInterface
{
    public function render(TeamMessageContent $message): string;
}
