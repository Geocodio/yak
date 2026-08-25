<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Agent-authored text (PR bodies, run summaries, review findings, log
 * messages) arrives as Markdown. This centralizes the two ways the
 * dashboard shows it: rendered to HTML, or flattened to plain text for
 * places that only have room for one line.
 */
class Markdown
{
    /**
     * Render Markdown to HTML with embedded HTML and unsafe links stripped.
     */
    public static function toHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * Flatten Markdown to a single line of plain text — no syntax, no tags,
     * no line breaks. For headings, badges, and other one-line surfaces.
     */
    public static function toPlainText(?string $markdown, ?int $limit = null): string
    {
        $text = html_entity_decode(
            strip_tags(self::toHtml($markdown)),
            ENT_QUOTES | ENT_HTML5,
        );

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $limit === null ? $text : Str::limit($text, $limit);
    }
}
