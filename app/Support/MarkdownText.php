<?php

namespace App\Support;

/**
 * Makes untrusted text safe to place inside a single line of GitHub-flavoured
 * Markdown, such as a table cell or list item.
 */
class MarkdownText
{
    /** Whitespace runs become one space, Markdown and HTML metacharacters are backslash-escaped. */
    public static function inline(string $text, int $max = 200): string
    {
        $escaped = (string) preg_replace('/[\\\\`*_{}\[\]<>()#+\-!|~]/', '\\\\$0', self::flatten($text, $max));

        return str_replace('@', "@\u{200B}", $escaped);
    }

    /** A code span that cannot be closed early: backticks never appear inside it, and table pipes stay escaped. */
    public static function code(string $text, int $max = 200): string
    {
        return '`' . str_replace(['`', '|'], ["'", '\\|'], self::flatten($text, $max)) . '`';
    }

    private static function flatten(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
