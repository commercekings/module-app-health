<?php
declare(strict_types=1);

namespace Vendor\NotificationTeams\Model;

use CommerceKings\AppHealth\Api\Data\TeamMessageContent;
use CommerceKings\AppHealth\Api\Data\TeamMessageSection;
use CommerceKings\AppHealth\Api\TeamMessageRendererInterface;

/**
 * Example: App health's messages as the Markdown a Teams message shows - the title bold, strong headings bold, "- "
 * lists, tables in a code block. The provider (the Teams module) puts the text into its card; a renderer that builds
 * the card itself can colour it by $message->severity (TeamMessageContent::PROBLEM, WARNING, RESOLVED, ...).
 */
class AppHealthRenderer implements TeamMessageRendererInterface
{
    public function render(TeamMessageContent $message): string
    {
        $parts = ['**' . $message->title . '**'];
        foreach ($message->sections as $section) {
            $parts[] = $this->section($section);
        }
        foreach ($message->hints as $hint) {
            $parts[] = '**What to do:** ' . $hint;
        }
        return implode("\n\n", array_filter($parts));
    }

    private function section(TeamMessageSection $section): string
    {
        $parts = [];
        if ($section->heading !== null) {
            $parts[] = $section->strongHeading ? '**' . $section->heading . '**' : $section->heading;
        }
        foreach ($section->tables as $table) {
            $rows = array_map(fn (array $row) => implode(' | ', $row), array_merge([$table->columns], $table->rows));
            $parts[] = '**' . $table->title . "**\n```\n" . implode("\n", $rows) . "\n```";
        }
        foreach ($section->lines as $line) {
            $parts[] = ($section->bullets ? '- ' : '') . $line;
        }
        return implode("\n", $parts);
    }
}
