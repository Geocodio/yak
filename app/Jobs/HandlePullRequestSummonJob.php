<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Channels\GitHub\FollowUpCommentParser;
use App\Channels\GitHub\PullRequestSummonReplier;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\ReviewFeedbackFormatter;
use App\Services\TaskLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Answers a `/yak` summon on a PR that Yak did not open. Yak always replies:
 * it either starts (or queues) work on the PR's own branch, or says why it
 * cannot. A review or comment with no summon in it is left alone.
 */
class HandlePullRequestSummonJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    private const string PREAMBLE = 'This pull request was opened by a person, not by Yak, and you have no earlier session on it. Work on top of the current branch. Add new commits only: never amend, rebase, or force-push.';

    /** Characters a branch name may hold before it is used in sandbox shell commands. */
    private const string SAFE_BRANCH_PATTERN = '#^[A-Za-z0-9._/-]+$#';

    public function __construct(
        public readonly string $repoFullName,
        public readonly int $prNumber,
        public readonly string $summonerLogin,
        public readonly ?int $reviewId = null,
        public readonly string $reviewBody = '',
        public readonly ?int $issueCommentId = null,
        public readonly string $issueCommentBody = '',
    ) {
        $this->onQueue('default');
    }

    public function handle(AppService $github, FollowUpTaskFactory $followUps, PullRequestSummonReplier $replier): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId <= 0) {
            return;
        }

        $summons = $this->collectSummons($github, $installationId);

        if ($summons === []) {
            return;
        }

        foreach ($summons as $summon) {
            if ($summon['reaction_id'] !== null) {
                $github->addReaction($installationId, $this->repoFullName, $summon['reaction_id'], 'eyes', isReviewComment: $summon['is_review_comment']);
            }
        }

        $threadId = $this->replyThread($summons);
        $refuse = fn (string $reason) => $replier->reply($this->repoFullName, $this->prNumber, $threadId, $reason);

        $repository = Repository::resolveFromGitHub(null, $this->repoFullName);

        if ($repository === null || ! $repository->is_active) {
            $refuse("I can't work on this pull request: this repository is not set up in Yak.");

            return;
        }

        $pullRequest = $github->getPullRequest($installationId, $this->repoFullName, $this->prNumber);
        $prUrl = (string) ($pullRequest['html_url'] ?? '');
        $branch = (string) data_get($pullRequest, 'head.ref', '');

        if ($prUrl === '' || ($pullRequest['state'] ?? '') !== 'open') {
            $refuse("This PR is already merged or closed, so I can't push more changes here. Open a new issue or task and I'll pick it up.");

            return;
        }

        if (data_get($pullRequest, 'head.repo.full_name') !== data_get($pullRequest, 'base.repo.full_name')) {
            $refuse("I can't push to this pull request because its branch is on a fork.");

            return;
        }

        if (preg_match(self::SAFE_BRANCH_PATTERN, $branch) !== 1) {
            $refuse("I can't work on this pull request: its branch name has characters I can't use safely.");

            return;
        }

        $instructions = self::PREAMBLE . "\n\n" . app(ReviewFeedbackFormatter::class)->format('', '', array_map(
            fn (array $summon): array => [
                'id' => $summon['tag_id'],
                'body' => $summon['body'],
                'author' => $this->summonerLogin,
                'file' => $summon['file'],
                'line' => $summon['line'],
                'diff_hunk' => $summon['diff_hunk'],
            ],
            $summons,
        ), '');

        $root = YakTask::followUpRootForPr($prUrl);

        if ($root === null) {
            $task = $this->createRootTask($repository, $prUrl, $branch, $instructions, $threadId);
            $replier->replyForTask($task, "On it. I'll push to `{$branch}` and reply here when I'm done.");

            return;
        }

        $busy = $root->conversation()
            ->first(fn (YakTask $member): bool => in_array($member->status, TriageReviewJob::BUSY_STATUSES, true));

        if ($busy !== null) {
            PendingSteeringMessage::queueFor($busy, $instructions, 'github');
            $refuse("I'm still working on an earlier request on this PR. I've queued this one and will pick it up next.\n\n" . PullRequestSummonReplier::taskLink($busy));

            return;
        }

        $child = $followUps->create($root, $instructions, 'github', authorName: $this->summonerLogin, summonReviewCommentId: $threadId);

        if ($child === null) {
            $refuse("This PR is already merged or closed, so I can't push more changes here. Open a new issue or task and I'll pick it up.");

            return;
        }

        $replier->replyForTask($child, "On it. I'll push to `{$branch}` and reply here when I'm done.");
    }

    /**
     * Every part of the review or comment that starts with a Yak prefix.
     *
     * @return array<int, array{body: string, tag_id: ?int, reaction_id: ?int, is_review_comment: bool, thread_id: ?int, file: ?string, line: ?int, diff_hunk: ?string}>
     */
    private function collectSummons(AppService $github, int $installationId): array
    {
        $parser = app(FollowUpCommentParser::class);
        $summons = [];

        if ($this->issueCommentId !== null) {
            $instructions = $parser->parse($this->issueCommentBody);

            if ($instructions !== null) {
                $summons[] = $this->summon($instructions, reactionId: $this->issueCommentId);
            }

            return $summons;
        }

        if ($this->reviewId === null) {
            return [];
        }

        $reviewInstructions = $parser->parse($this->reviewBody);

        if ($reviewInstructions !== null) {
            $summons[] = $this->summon($reviewInstructions);
        }

        $comments = array_filter($github->listReviewComments($installationId, $this->repoFullName, $this->prNumber, $this->reviewId), 'is_array');

        foreach ($comments as $comment) {
            $instructions = $parser->parse((string) ($comment['body'] ?? ''));
            $commentId = (int) ($comment['id'] ?? 0);

            if ($instructions === null || $commentId <= 0) {
                continue;
            }

            $parentId = isset($comment['in_reply_to_id']) ? (int) $comment['in_reply_to_id'] : null;
            $parent = $parentId !== null ? $github->getReviewComment($installationId, $this->repoFullName, $parentId) : null;

            if ($parent !== null) {
                $quoted = implode("\n", array_map(fn (string $line): string => '  > ' . $line, explode("\n", trim($parent['body']))));
                $instructions .= "\n\n  In reply to @{$parent['author']}:\n{$quoted}";
            }

            $summons[] = [
                'body' => $instructions,
                'tag_id' => $commentId,
                'reaction_id' => $commentId,
                'is_review_comment' => true,
                'thread_id' => $parentId ?? $commentId,
                'file' => isset($comment['path']) ? (string) $comment['path'] : null,
                'line' => isset($comment['line']) ? (int) $comment['line'] : (isset($comment['original_line']) ? (int) $comment['original_line'] : null),
                'diff_hunk' => isset($comment['diff_hunk']) ? (string) $comment['diff_hunk'] : null,
            ];
        }

        return $summons;
    }

    /**
     * A conversation-tab summon: no file, no thread, no reply tag.
     *
     * @return array{body: string, tag_id: ?int, reaction_id: ?int, is_review_comment: bool, thread_id: ?int, file: ?string, line: ?int, diff_hunk: ?string}
     */
    private function summon(string $instructions, ?int $reactionId = null): array
    {
        return [
            'body' => $instructions,
            'tag_id' => null,
            'reaction_id' => $reactionId,
            'is_review_comment' => false,
            'thread_id' => null,
            'file' => null,
            'line' => null,
            'diff_hunk' => null,
        ];
    }

    /**
     * The review thread of the first inline summon, or null to answer on the
     * PR conversation.
     *
     * @param  array<int, array{thread_id: ?int}>  $summons
     */
    private function replyThread(array $summons): ?int
    {
        foreach ($summons as $summon) {
            if ($summon['thread_id'] !== null) {
                return $summon['thread_id'];
            }
        }

        return null;
    }

    private function createRootTask(Repository $repository, string $prUrl, string $branch, string $instructions, ?int $threadId): YakTask
    {
        $task = YakTask::create([
            'source' => 'github',
            'repo' => $repository->slug,
            'mode' => TaskMode::Fix,
            'branch_name' => $branch,
            'pr_url' => $prUrl,
            'pr_number' => $this->prNumber,
            'external_id' => $prUrl . '-summon',
            'external_url' => $prUrl,
            'description' => $instructions,
            'author_name' => $this->summonerLogin,
            'targets_external_pr' => true,
            'summon_review_comment_id' => $threadId,
            'status' => TaskStatus::Pending,
        ]);

        TaskLogger::info($task, 'Summoned on a human-authored PR', ['summoner' => $this->summonerLogin]);

        RunFollowUpJob::dispatch($task);

        return $task;
    }
}
