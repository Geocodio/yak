<?php

namespace App\Support;

/**
 * Finds the 1-based line of a dotted key in YAML text by indentation, without
 * parsing. Numeric segments select the Nth `- ` item of a block list. Flow
 * collections and anchors are not followed; any key it cannot find gives line 1.
 */
class YamlKeyLocator
{
    public static function line(string $yaml, string $dottedKey): int
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $cursor = 0;
        $parentIndent = -1;

        foreach (explode('.', $dottedKey) as $segment) {
            $match = ctype_digit($segment)
                ? self::findItem($lines, $cursor, $parentIndent, (int) $segment)
                : self::findKey($lines, $cursor, $parentIndent, $segment);

            if ($match === null) {
                return 1;
            }

            [$cursor, $parentIndent] = $match;
        }

        return $cursor + 1;
    }

    /**
     * @param  list<string>  $lines
     * @return array{int, int}|null the matching line index and the indent its children must exceed
     */
    private static function findKey(array $lines, int $cursor, int $parentIndent, string $key): ?array
    {
        for ($index = $cursor; $index < count($lines); $index++) {
            $text = ltrim($lines[$index]);

            if ($text === '' || $text[0] === '#') {
                continue;
            }

            $indent = strlen($lines[$index]) - strlen($text);

            if ($indent <= $parentIndent && $index !== $cursor) {
                return null;
            }

            if (preg_match('/^((?:- +)?)(["\']?)' . preg_quote($key, '/') . '\2\s*:(\s|$)/', $text, $matches) === 1) {
                return [$index, $indent + strlen($matches[1])];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return array{int, int}|null the item's line index and its dash indent
     */
    private static function findItem(array $lines, int $cursor, int $parentIndent, int $position): ?array
    {
        $itemIndent = null;
        $seen = 0;

        for ($index = $cursor + 1; $index < count($lines); $index++) {
            $text = ltrim($lines[$index]);

            if ($text === '' || $text[0] === '#') {
                continue;
            }

            $indent = strlen($lines[$index]) - strlen($text);
            $isItem = str_starts_with($text, '- ') || $text === '-';

            if ($indent < $parentIndent || ($indent === $parentIndent && ! $isItem)) {
                return null;
            }

            if (! $isItem || ($itemIndent !== null && $indent !== $itemIndent)) {
                continue;
            }

            $itemIndent ??= $indent;

            if ($seen++ === $position) {
                return [$index, $indent];
            }
        }

        return null;
    }
}
