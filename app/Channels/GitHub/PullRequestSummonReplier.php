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

    public function replyForTask(YakTask $task, string $body): void
    {
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
