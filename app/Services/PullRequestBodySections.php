<?php

namespace App\Services;

/**
 * Yak owns a few HTML-comment-delimited blocks inside a pull request body.
 * Each block is replaced wholesale by whichever job owns it, and nothing
 * outside the markers is ever touched, so a human can edit the rest of
 * the body freely.
 */
final class PullRequestBodySections
{
    public const string DESCRIPTION = 'description';

    public const string SCREENSHOTS = 'screenshots';

    public const string WALKTHROUGH = 'walkthrough';

    public static function startMarker(string $name): string
    {
        return "<!-- yak:{$name} -->";
    }

    public static function endMarker(string $name): string
    {
        return "<!-- /yak:{$name} -->";
    }

    public static function wrap(string $name, string $content): string
    {
        return self::startMarker($name) . "\n{$content}\n" . self::endMarker($name);
    }

    public static function has(string $body, string $name): bool
    {
        return str_contains($body, self::startMarker($name)) && str_contains($body, self::endMarker($name));
    }

    /**
     * `$section` is the already-wrapped block. Returns the body unchanged
     * when the markers are absent; callers decide whether to append.
     */
    public static function replace(string $body, string $name, string $section): string
    {
        if (! self::has($body, $name)) {
            return $body;
        }

        $pattern = '/' . preg_quote(self::startMarker($name), '/') . '.*?' . preg_quote(self::endMarker($name), '/') . '/s';

        // preg_replace_callback (not preg_replace) so a literal '$1' or a
        // trailing backslash in the section is never read as a backreference.
        return preg_replace_callback($pattern, fn (): string => $section, $body, 1) ?? $body;
    }
}
