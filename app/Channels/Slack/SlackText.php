<?php

namespace App\Channels\Slack;

/**
 * Escapes untrusted text for Slack mrkdwn. Slack reads `&`, `<` and `>` as
 * control characters; a `|` ends the URL part of a `<url|text>` link, so it
 * is replaced inside link text.
 */
class SlackText
{
    public static function escape(string $text, bool $isLinkText = false): string
    {
        $escaped = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return $isLinkText ? str_replace('|', '¦', $escaped) : $escaped;
    }
}
