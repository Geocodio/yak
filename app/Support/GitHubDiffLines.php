<?php

namespace App\Support;

/**
 * Extracts the set of (file, line) pairs that can legally carry a
 * GitHub pull-request review comment.
 *
 * GitHub rejects comments whose `line` isn't inside a diff hunk (422
 * Validation Failed). On the RIGHT side of the diff the valid lines are
 * every added (`+`) and context (` `) line inside a hunk header
 * `@@ -a,b +c,d @@`.
 */
class GitHubDiffLines
{
    /**
     * @param  array<int, array<string, mixed>>  $prFiles  items from GET /pulls/{n}/files
     * @return array<string, array<int, true>> map of filename → set of valid line numbers
     */
    public static function buildMap(array $prFiles): array
    {
        $map = [];

        foreach ($prFiles as $file) {
            $filename = (string) ($file['filename'] ?? '');
            $patch = (string) ($file['patch'] ?? '');

            if ($filename === '' || $patch === '') {
                continue;
            }

            $map[$filename] = self::extractRightSideLines($patch);
        }

        return $map;
    }

    /**
     * @return array<int, true>
     */
    private static function extractRightSideLines(string $patch): array
    {
        $valid = [];
        $currentLine = 0;
        $inHunk = false;

        foreach (preg_split('/\R/', $patch) ?: [] as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m) === 1) {
                $currentLine = (int) $m[1];
                $inHunk = true;

                continue;
            }

            if (! $inHunk) {
                continue;
            }

            if ($line === '' || $line[0] === ' ') {
                // Context line: counts against the RIGHT side and is commentable.
                $valid[$currentLine] = true;
                $currentLine++;

                continue;
            }

            if ($line[0] === '+') {
                // Added line: commentable.
                $valid[$currentLine] = true;
                $currentLine++;

                continue;
            }

            if ($line[0] === '-') {
                // Deleted line: does not consume a RIGHT-side line.
                continue;
            }

            if ($line[0] === '\\') {
                // "\ No newline at end of file" — no-op.
                continue;
            }

            // Anything else (next hunk header handled above, stray lines
            // shouldn't happen): fall out of hunk mode to be safe.
            $inHunk = false;
        }

        return $valid;
    }
}
