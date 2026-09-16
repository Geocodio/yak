<?php

namespace App\Services;

/**
 * Renders review feedback as the plain-text instructions a follow-up task
 * receives. Both the prefixed-comment batch and the review triage path use
 * it, so inline comments look the same to Claude whichever way they came in.
 */
class ReviewFeedbackFormatter
{
    /**
     * @param  array<int, array{body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>  $comments
     */
    public function format(string $state, string $summary, array $comments, string $reviewer): string
    {
        $lines = [];

        if ($state === '' && $reviewer === '') {
            $lines[] = 'The following feedback was left on the pull request:';
            $lines[] = '';
            array_push($lines, ...$this->commentLines($comments));

            return implode("\n", $lines);
        }

        $label = str_replace('_', ' ', $state);
        $lines[] = "@{$reviewer} submitted a review ({$label}):";

        $summary = trim($summary);

        if ($summary !== '') {
            $lines[] = '';

            foreach (explode("\n", $summary) as $summaryLine) {
                $lines[] = '> ' . $summaryLine;
            }
        }

        if ($comments !== []) {
            $lines[] = '';
            $lines[] = 'Inline comments:';
            $lines[] = '';
            array_push($lines, ...$this->commentLines($comments));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>  $comments
     * @return array<int, string>
     */
    private function commentLines(array $comments): array
    {
        $lines = [];

        foreach ($comments as $comment) {
            $anchor = $comment['file'] !== null
                ? $comment['file'] . ($comment['line'] !== null ? ":{$comment['line']}" : '') . ' — '
                : '';

            $lines[] = "- {$anchor}{$comment['body']}";

            if ($comment['diff_hunk'] !== null && $comment['diff_hunk'] !== '') {
                $lines[] = '';
                $lines[] = '  ```diff';

                foreach (explode("\n", $comment['diff_hunk']) as $hunkLine) {
                    $lines[] = '  ' . $hunkLine;
                }

                $lines[] = '  ```';
            }
        }

        return $lines;
    }
}
