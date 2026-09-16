<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Channels\GitHub\FollowUpCommentParser;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\ReviewFeedbackFormatter;
use App\Services\ReviewFeedbackTriageDecision;
use App\Services\TaskLogger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns one submitted GitHub review on a Yak PR into either nothing (praise,
 * approval) or a follow-up run. Inline comments come from the API rather
 * than from individual webhooks, so a review is handled as one unit no
 * matter how its comment events were ordered.
 */
class TriageReviewJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    /** Task statuses that mean the PR's branch is already being worked on. */
    private const array BUSY_STATUSES = [
        TaskStatus::Pending,
        TaskStatus::Running,
        TaskStatus::AwaitingCi,
        TaskStatus::Retrying,
    ];

    public function __construct(
        public readonly int $taskId,
        public readonly int $reviewId,
        public readonly int $prNumber,
        public readonly string $reviewState,
        public readonly string $reviewBody,
        public readonly string $reviewerLogin,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "review:{$this->reviewId}";
    }

    /** Double the job timeout, so a lock orphaned by a killed worker expires. */
    public function uniqueFor(): int
    {
        return 120;
    }

    public function handle(AppService $github): void
    {
        $task = YakTask::find($this->taskId);

        if ($task === null || $task->mode === TaskMode::Review || ! $task->prIsOpen()) {
            return;
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $repoSlug = Repository::githubNameFor((string) $task->repo);

        $comments = $this->inlineComments($github, $installationId, $repoSlug);
        $reactions = $this->reactWithEyes($github, $installationId, $repoSlug, $comments);

        $instructions = app(ReviewFeedbackFormatter::class)->format(
            $this->reviewState,
            $this->reviewBody,
            $comments,
            $this->reviewerLogin,
        );

        $decision = $this->decide($task, $comments, $instructions);

        TaskLogger::info($task, "Review triaged: {$decision}", [
            'review_id' => $this->reviewId,
            'state' => $this->reviewState,
            'reviewer' => $this->reviewerLogin,
            'inline_comments' => count($comments),
        ]);

        if ($decision === ReviewFeedbackTriageDecision::NONE) {
            $this->acknowledge($github, $installationId, $repoSlug, $reactions);

            return;
        }

        $active = $task->conversation()
            ->first(fn (YakTask $member): bool => in_array($member->status, self::BUSY_STATUSES, true));

        if ($active !== null) {
            PendingSteeringMessage::queueFor($active, $instructions, 'github_review', reviewerLogin: $this->reviewerLogin);
            TaskLogger::info($task, 'Review queued behind an active run', ['active_task_id' => $active->id]);

            return;
        }

        app(FollowUpTaskFactory::class)->create(
            $task,
            $instructions,
            'github',
            authorName: $this->reviewerLogin,
            reRequestReviewFrom: [$this->reviewerLogin],
        );
    }

    /**
     * @return array<int, array{id: int, body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>
     */
    private function inlineComments(AppService $github, int $installationId, string $repoSlug): array
    {
        $raw = $github->listReviewComments($installationId, $repoSlug, $this->prNumber, $this->reviewId);

        // An error payload (404/403/410, e.g. {"message":"Not Found"}) is a
        // non-empty array whose entries are not comment shapes; drop them so
        // the job proceeds on the review body alone instead of throwing.
        $raw = array_filter($raw, 'is_array');

        return array_values(array_map(fn (array $comment): array => [
            'id' => (int) ($comment['id'] ?? 0),
            'body' => (string) ($comment['body'] ?? ''),
            'author' => isset($comment['user']['login']) ? (string) $comment['user']['login'] : null,
            'file' => isset($comment['path']) ? (string) $comment['path'] : null,
            'line' => isset($comment['line']) ? (int) $comment['line'] : (isset($comment['original_line']) ? (int) $comment['original_line'] : null),
            'diff_hunk' => isset($comment['diff_hunk']) ? (string) $comment['diff_hunk'] : null,
        ], $raw));
    }

    /**
     * @param  array<int, array{id: int, body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>  $comments
     * @return array<int, int|null> comment id => reaction id
     */
    private function reactWithEyes(AppService $github, int $installationId, string $repoSlug, array $comments): array
    {
        $reactions = [];

        foreach ($comments as $comment) {
            if ($comment['id'] > 0) {
                $reactions[$comment['id']] = $github->addReaction($installationId, $repoSlug, $comment['id'], 'eyes', isReviewComment: true);
            }
        }

        return $reactions;
    }

    /**
     * @param  array<int, array{id: int, body: string, author: ?string, file: ?string, line: ?int, diff_hunk: ?string}>  $comments
     */
    private function decide(YakTask $task, array $comments, string $instructions): string
    {
        if ($this->reviewState === 'changes_requested') {
            return ReviewFeedbackTriageDecision::ACT;
        }

        $parser = app(FollowUpCommentParser::class);

        if ($parser->parse($this->reviewBody) !== null) {
            return ReviewFeedbackTriageDecision::ACT;
        }

        foreach ($comments as $comment) {
            if ($parser->parse($comment['body']) !== null) {
                return ReviewFeedbackTriageDecision::ACT;
            }
        }

        return app(ReviewFeedbackTriageDecision::class)->decide((string) $task->description, $instructions);
    }

    /**
     * @param  array<int, int|null>  $reactions
     */
    private function acknowledge(AppService $github, int $installationId, string $repoSlug, array $reactions): void
    {
        foreach ($reactions as $commentId => $reactionId) {
            $github->addReaction($installationId, $repoSlug, $commentId, '+1', isReviewComment: true);

            if ($reactionId !== null) {
                $github->removeReaction($installationId, $repoSlug, $commentId, $reactionId, isReviewComment: true);
            }
        }
    }
}
