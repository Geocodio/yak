<?php

namespace App\Support;

/**
 * Matches a file path against a list of fnmatch-style glob patterns.
 *
 * Rules:
 * - Patterns use `*`, `**`, and `?` as in gitignore-style globs.
 * - Matching is anchored to the full path for patterns containing `/`.
 * - Patterns without `/` (e.g. `*.min.js`) match any segment of the path.
 * - Returns true if any pattern matches. Empty pattern list → false.
 */
class PathMatcher
{
    /**
     * @param  array<int, string>  $patterns
     */
    public static function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::singleMatch($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compiles one glob into a matcher that reuses its regex. The matcher
     * returns null when PCRE fails on a path (for example the backtrack
     * limit), so callers can tell "could not check" from "no match".
     *
     * @return \Closure(string): ?bool
     */
    public static function compile(string $pattern): \Closure
    {
        $regex = self::globToRegex($pattern);
        $isAnchored = str_contains($pattern, '/');

        return static function (string $path) use ($regex, $isAnchored): ?bool {
            $result = preg_match($regex, $path);

            if ($result === false) {
                return null;
            }

            if ($result === 1 || $isAnchored) {
                return $result === 1;
            }

            $basenameResult = preg_match($regex, basename($path));

            return $basenameResult === false ? null : $basenameResult === 1;
        };
    }

    private static function singleMatch(string $path, string $pattern): bool
    {
        return self::compile($pattern)($path) === true;
    }

    private static function globToRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $c = $pattern[$i];

            if ($c === '*' && ($pattern[$i + 1] ?? '') === '*') {
                $regex .= '.*';
                $i++;
            } elseif ($c === '*') {
                $regex .= '[^/]*';
            } elseif ($c === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($c, '#');
            }
        }

        return '#^' . $regex . '$#';
    }
}
