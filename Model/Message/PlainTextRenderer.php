<?php
declare(strict_types=1);

namespace CommerceKings\AppHealth\Model\Message;

use CommerceKings\AppHealth\Api\Data\TeamMessageContent;
use CommerceKings\AppHealth\Api\Data\TeamMessageSection;
use CommerceKings\AppHealth\Api\TeamMessageRendererInterface;

/**
 * Plain text, for a channel without a renderer of its own (email, SMS, a provider that takes text): no markup - the
 * title on the first line, each section after a blank line, list lines as "- ", a table as aligned text under its
 * title - then "What to do: <hint>" lines.
 */
class PlainTextRenderer implements TeamMessageRendererInterface
{
    public function render(TeamMessageContent $message): string
    {
        $parts = [$message->title];
        foreach ($message->sections as $section) {
            $part = $this->section($section);
            if ($part !== '') {
                $parts[] = $part;
            }
        }
        $hints = array_filter($message->hints, fn (string $hint) => $hint !== '');
        if ($hints !== []) {
            $parts[] = implode("\n", array_map(fn (string $hint) => 'What to do: ' . $hint, $hints));
        }
        return implode("\n\n", $parts);
    }

    private function section(TeamMessageSection $section): string
    {
        $parts = [];
        if ($section->heading !== null) {
            $parts[] = $section->heading;
        }
        foreach ($section->tables as $table) {
            $parts[] = $table->title . "\n" . SlackRenderer::table($table);
        }
        if ($section->lines !== []) {
            $parts[] = implode($section->spaced ? "\n\n" : "\n", array_map(fn (string $line) => $section->bullets
                ? '- ' . $line : $line, $section->lines));
        }
        return implode("\n", $parts);
    }
}
