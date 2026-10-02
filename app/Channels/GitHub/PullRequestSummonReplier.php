<?php

namespace App\Channels\GitHub;

use App\Models\Repository;
use App\Models\YakTask;
use Throwable;

/**
 * Posts Yak's answer to a summon where the summon was made: in the review
 * thread for an inline comment, on the PR conversation otherwise.
 */
class PullRequestSummonReplier
{
    public function __construct(private readonly AppService $github) {}

    public function reply(string $repoFullName, int $prNumber, ?int $threadCommentId, string $body): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId <= 0 || $prNumber <= 0) {
            return;
        }

        if ($threadCommentId !== null) {
            try {
                $this->github->replyToReviewComment($installationId, $repoFullName, $prNumber, $threadCommentId, $body);

                return;
            } catch (Throwable) {
                // The thread is gone or locked; the PR conversation still reaches the summoner.
            }
        }

        $this->github->commentOnPullRequest($installationId, $repoFullName, $prNumber, $body);
    }

    /**
     * With `$quoteSummon`, an answer on the PR conversation opens with the
     * summoning comment quoted, the way GitHub's "Quote reply" does, since a
     * conversation comment has no thread to reply in.
     */
    public function replyForTask(YakTask $task, string $body, bool $quoteSummon = false): void
    {
        if ($quoteSummon && $task->summon_review_comment_id === null && $task->summon_quote !== null && $task->summon_quote !== '') {
            $body = implode("\n", array_map(fn (string $line): string => '> ' . $line, explode("\n", $task->summon_quote))) . "\n\n" . $body;
        }

        $this->reply(
            Repository::githubNameFor((string) $task->repo),
            (int) $task->pr_number,
            $task->summon_review_comment_id,
            $body . "\n\n" . self::taskLink($task),
        );
    }

    public static function taskLink(YakTask $task): string
    {
        return '[View task](' . route('tasks.show', $task) . ')';
    }
}
