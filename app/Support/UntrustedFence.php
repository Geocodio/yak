<?php

namespace App\Support;

/**
 * Keeps untrusted text (CI logs, branch names) from escaping the block or
 * line it is placed in when it is embedded in a prompt.
 */
class UntrustedFence
{
    /**
     * Wraps text in a code fence longer than any backtick run inside it.
     */
    public static function wrap(string $text, string $label = ''): string
    {
        preg_match_all('/`+/', $text, $matches);
        $longestRun = max(array_map('strlen', $matches[0] ?: ['']));
        $fence = str_repeat('`', max(3, $longestRun + 1));

        return "{$fence}{$label}\n{$text}\n{$fence}";
    }

    /**
     * Reduces a value to one line without backticks.
     */
    public static function inline(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $value)));
    }
}
