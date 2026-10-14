<?php

namespace App\Support;

/**
 * Expands brace groups such as `{a,b}` into separate globs.
 *
 * - Several and nested groups expand in a stable, left-to-right order.
 * - A glob without braces, or with unbalanced braces, is returned unchanged.
 * - Throws when one glob expands to more than MAX_EXPANSIONS entries.
 */
class GlobExpander
{
    public const MAX_EXPANSIONS = 50;

    /**
     * @return list<string>
     */
    public static function expand(string $glob): array
    {
        if (! self::isBalanced($glob)) {
            return [$glob];
        }

        $expanded = self::expandBalanced($glob);

        if (count($expanded) > self::MAX_EXPANSIONS) {
            throw new \InvalidArgumentException(
                'Glob ' . $glob . ' expands to more than ' . self::MAX_EXPANSIONS . ' entries.'
            );
        }

        return $expanded;
    }

    private static function isBalanced(string $glob): bool
    {
        $depth = 0;

        foreach (str_split($glob) as $character) {
            if ($character === '{') {
                $depth++;
            } elseif ($character === '}' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * @return list<string>
     */
    private static function expandBalanced(string $glob): array
    {
        $open = strpos($glob, '{');

        if ($open === false) {
            return [$glob];
        }

        $alternatives = [];
        $current = '';
        $depth = 0;

        for ($index = $open; $index < strlen($glob); $index++) {
            $character = $glob[$index];

            if ($character === '{' && $depth++ === 0) {
                continue;
            }

            if ($character === '}' && --$depth === 0) {
                $alternatives[] = $current;
                break;
            }

            if ($character === ',' && $depth === 1) {
                $alternatives[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $prefix = substr($glob, 0, $open);
        $suffix = substr($glob, $index + 1);
        $results = [];

        foreach ($alternatives as $alternative) {
            foreach (self::expandBalanced($prefix . $alternative . $suffix) as $expanded) {
                $results[] = $expanded;

                if (count($results) > self::MAX_EXPANSIONS) {
                    return $results;
                }
            }
        }

        return $results;
    }
}
