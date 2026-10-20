<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Test\Unit\Stub;

use CommerceKings\AppHealth\Model\Message\PlainTextRenderer;
use CommerceKings\AppHealth\Model\Message\RendererPool;
use CommerceKings\AppHealth\Model\Message\SlackRenderer;

/** The renderers as etc/di.xml sets them: Slack for "slack", plain text for the rest. */
class Renderers
{
    public static function pool(): RendererPool
    {
        return new RendererPool(new PlainTextRenderer(), ['slack' => new SlackRenderer()]);
    }
}
