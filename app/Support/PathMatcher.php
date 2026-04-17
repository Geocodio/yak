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

    private static function singleMatch(string $path, string $pattern): bool
    {
        $regex = self::globToRegex($pattern);

        if (str_contains($pattern, '/')) {
            return preg_match($regex, $path) === 1;
        }

        $basename = basename($path);

        return preg_match($regex, $basename) === 1 || preg_match($regex, $path) === 1;
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
            } elseif (in_array($c, ['.', '+', '(', ')', '[', ']', '^', '$', '|', '\\'], true)) {
                $regex .= '\\' . $c;
            } else {
                $regex .= $c;
            }
        }

        return '#^' . $regex . '$#';
    }
}
