<?php

namespace App\Services;

/**
 * Renders review feedback as the plain-text instructions a follow-up task
 * receives. Both the prefixed-comment batch and the review triage path use
 * it, so inline comments look the same to Claude whichever way they came in.
 * Inline review comments carry a `[c:<id>]` tag so the agent can address its
 * reply to that thread; conversation-tab comments have no thread and no tag.
 */
class ReviewFeedbackFormatter
{
    /**
     * @param  array<int, array{id: ?int, body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>  $comments
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
     * The same feedback as structured data, so the thread can show it the
     * way GitHub does and link each part back to the pull request.
     *
     * @param  array<int, array{id: ?int, body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>  $comments
     * @return array{reviewer: ?string, state: string, summary: string, url: ?string, comments: list<array{id: ?int, author: ?string, body: string, file: ?string, line: ?int, diff_hunk: ?string, url: ?string}>}
     */
    public function toReviewFeedback(string $state, string $summary, array $comments, string $reviewer, ?string $prUrl, ?int $reviewId = null): array
    {
        return [
            'reviewer' => $reviewer !== '' ? $reviewer : null,
            'state' => $state,
            'summary' => trim($summary),
            'url' => $prUrl !== null && $reviewId !== null ? "{$prUrl}#pullrequestreview-{$reviewId}" : $prUrl,
            'comments' => array_values(array_map(fn (array $comment): array => [
                'id' => $comment['id'],
                'author' => $comment['author'],
                'body' => $comment['body'],
                'file' => $comment['file'],
                'line' => $comment['line'],
                'diff_hunk' => $comment['diff_hunk'],
                'url' => $prUrl !== null && $comment['file'] !== null && ($comment['id'] ?? 0) > 0 ? "{$prUrl}#discussion_r{$comment['id']}" : null,
            ], $comments)),
        ];
    }

    /**
     * @param  array<int, array{id: ?int, body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>  $comments
     * @return array<int, string>
     */
    private function commentLines(array $comments): array
    {
        $lines = [];

        foreach ($comments as $comment) {
            $anchor = $comment['file'] !== null
                ? $comment['file'] . ($comment['line'] !== null ? ":{$comment['line']}" : '') . ' — '
                : '';

            $tag = ($comment['id'] ?? null) !== null && $comment['id'] > 0 ? "[c:{$comment['id']}] " : '';

            $lines[] = "- {$tag}{$anchor}{$comment['body']}";

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
