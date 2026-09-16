<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\Models\YakTask;
use Throwable;

/**
 * Posts a follow-up run's replies on the review-comment threads they answer,
 * so a reviewer reads the answer where they asked. A thread GitHub will not
 * accept a reply on (comment deleted, PR moved) gets a general PR comment
 * that quotes the original comment instead, so the context is not lost.
 */
class ReviewReplyPoster
{
    public function __construct(private readonly AppService $github) {}

    public function post(YakTask $task, string $repoSlug, int $prNumber): void
    {
        $replies = (array) ($task->review_replies ?? []);
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($replies === []) {
            return;
        }

        if ($installationId <= 0) {
            TaskLogger::warning($task, 'Review replies skipped: no GitHub installation id');

            return;
        }

        foreach ($replies as $commentId => $body) {
            $commentId = (int) $commentId;
            $body = trim((string) $body);

            if ($commentId <= 0 || $body === '') {
                continue;
            }

            try {
                $this->github->replyToReviewComment($installationId, $repoSlug, $prNumber, $commentId, $body);
                TaskLogger::info($task, 'Replied on review comment', ['comment_id' => $commentId]);
            } catch (Throwable $threadError) {
                $this->postFallback($task, $installationId, $repoSlug, $prNumber, $commentId, $body, $threadError);
            }
        }
    }

    private function postFallback(YakTask $task, int $installationId, string $repoSlug, int $prNumber, int $commentId, string $body, Throwable $threadError): void
    {
        $quote = $this->originalCommentLine($task, $commentId);
        $intro = $quote !== null
            ? "Replying to a review comment (GitHub would not accept a thread reply):\n\n> {$quote}\n\n"
            : "Replying to review comment {$commentId} (GitHub would not accept a thread reply):\n\n";

        try {
            $posted = $this->github->commentOnPullRequest($installationId, $repoSlug, $prNumber, $intro . $body);
        } catch (Throwable $fallbackError) {
            TaskLogger::warning($task, 'Review reply could not be posted', [
                'comment_id' => $commentId,
                'thread_error' => $threadError->getMessage(),
                'fallback_error' => $fallbackError->getMessage(),
            ]);

            return;
        }

        if (! $posted) {
            TaskLogger::warning($task, 'Review reply could not be posted', [
                'comment_id' => $commentId,
                'thread_error' => $threadError->getMessage(),
                'fallback_error' => 'GitHub rejected the PR comment',
            ]);

            return;
        }

        TaskLogger::warning($task, 'Thread reply failed; posted as a PR comment', [
            'comment_id' => $commentId,
            'error' => $threadError->getMessage(),
        ]);
    }

    /**
     * The `[c:<id>] file:line — body` line the follow-up prompt showed the
     * agent, minus the tag, so the fallback comment can quote what was asked.
     */
    private function originalCommentLine(YakTask $task, int $commentId): ?string
    {
        $pattern = '/^-\s*\[c:' . $commentId . '\]\s*(.+)$/m';

        if (preg_match($pattern, (string) $task->description, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }
}
