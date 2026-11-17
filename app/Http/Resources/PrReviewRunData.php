<?php

namespace App\Http\Resources;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\YakTask;
use App\Support\Markdown;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the review page of one pull request shows: one card per review run (a review task of that repository and
 * pull request, with its `PrReview` row once it wrote one), newest first, and the totals of the side panel.
 *
 * @phpstan-type Run array{id: string, status: string, scope: string, startedAt: string, commit: ?string, model: ?string, stepCount: int, durationSeconds: int, costUsd: float, verdict: ?string, counts: array{mustFix: int, shouldFix: int, consider: int}, resolvedCount: int, findings: ?array<string, mixed>, error: ?string, canRerun: bool, logUrl: string, isLive: bool, latestActivity: ?string}
 * @phpstan-type Panel array{repository: string, author: ?string, state: string, branch: ?string, preview: ?array{status: string, hostname: string, url: string}, runCount: int, failedCount: int, totalCostUsd: float, helpfulCount: int, notHelpfulCount: int, thread: ?array{id: string, title: string}, source: ?array{label: string, url: string}}
 */
final class PrReviewRunData
{
    /**
     * @return Collection<int, YakTask>
     */
    public static function tasks(string $repoSlug, int $prNumber): Collection
    {
        return YakTask::query()
            ->where('mode', TaskMode::Review)
            ->where('repo', $repoSlug)
            ->where(fn ($query) => $query->where('pr_url', self::pullRequestUrl($repoSlug, $prNumber))->orWhere('pr_number', $prNumber))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public static function belongsToPullRequest(YakTask $task, string $repoSlug, int $prNumber): bool
    {
        return $task->mode === TaskMode::Review && $task->repo === $repoSlug && $task->pullRequestNumber() === $prNumber;
    }

    public static function pullRequestUrl(string $repoSlug, int $prNumber): string
    {
        return "https://github.com/{$repoSlug}/pull/{$prNumber}";
    }

    /**
     * @param  Collection<int, YakTask>  $tasks
     * @return array{runs: list<Run>, panel: Panel}
     */
    public static function build(Collection $tasks, string $repoSlug, int $prNumber): array
    {
        $reviews = PrReview::query()->whereIn('yak_task_id', $tasks->pluck('id'))->with('comments')->get()->keyBy('yak_task_id');

        $runs = $tasks->map(fn (YakTask $task): array => self::run($task, $reviews->get($task->id), $repoSlug, $prNumber))->values()->all();

        return ['runs' => $runs, 'panel' => self::panel($tasks, $reviews, $runs, $repoSlug, $prNumber)];
    }

    /**
     * @return Run
     */
    private static function run(YakTask $task, ?PrReview $review, string $repoSlug, int $prNumber): array
    {
        /** @var TaskStatus $taskStatus */
        $taskStatus = $task->status;
        $status = self::status($taskStatus);
        $isLive = in_array($status, ['queued', 'reviewing'], true);
        $comments = $review?->comments ?? new Collection;
        $context = self::context($task);
        $summary = $review?->summary ?? $task->result_summary;

        return [
            'id' => $task->public_id,
            'status' => $status,
            'scope' => (string) ($review->review_scope ?? $context['review_scope'] ?? 'full'),
            'startedAt' => ($task->started_at ?? $task->created_at)->toIso8601String(),
            'commit' => ($sha = $review->commit_sha_reviewed ?? $context['head_sha'] ?? null) === null ? null : substr((string) $sha, 0, 7),
            'model' => $task->resolvedModel(),
            'stepCount' => (int) $task->num_turns,
            'durationSeconds' => intdiv((int) $task->duration_ms, 1000),
            'costUsd' => (float) $task->cost_usd,
            'verdict' => filled($summary) ? Markdown::toHtml((string) $summary) : null,
            'counts' => self::counts($comments),
            'resolvedCount' => $comments->where('resolution_status', 'fixed')->count(),
            'findings' => $review === null ? null : self::findings($review, $comments),
            'error' => $taskStatus === TaskStatus::Failed || $taskStatus === TaskStatus::Expired ? ($task->error_log ?: 'The review stopped without a recorded reason.') : null,
            'canRerun' => ! in_array($taskStatus, [TaskStatus::Pending, TaskStatus::Running], true),
            'logUrl' => route('pr-reviews.for-pr.runs.log', ['repoSlug' => $repoSlug, 'prNumber' => $prNumber, 'task' => $task->public_id]),
            'isLive' => $isLive,
            'latestActivity' => $isLive ? self::latestActivity($task) : null,
        ];
    }

    private static function status(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Pending => 'queued',
            TaskStatus::Success => 'reviewed',
            TaskStatus::Failed, TaskStatus::Expired => 'failed',
            TaskStatus::Cancelled => 'cancelled',
            default => 'reviewing',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(YakTask $task): array
    {
        $context = json_decode((string) $task->context, true);

        return is_array($context) ? $context : [];
    }

    private static function latestActivity(YakTask $task): ?string
    {
        $log = TaskLog::query()->where('yak_task_id', $task->id)->latest('id')->first();

        return $log === null ? null : Str::limit((string) TranscriptData::entry($log)['text'], 160);
    }

    /**
     * @param  Collection<int, PrReviewComment>  $comments
     * @return array{mustFix: int, shouldFix: int, consider: int}
     */
    private static function counts(Collection $comments): array
    {
        return [
            'mustFix' => $comments->where('severity', 'must_fix')->count(),
            'shouldFix' => $comments->where('severity', 'should_fix')->count(),
            'consider' => $comments->where('severity', 'consider')->count(),
        ];
    }

    /**
     * @param  Collection<int, PrReviewComment>  $comments
     * @return array<string, mixed>
     */
    private static function findings(PrReview $review, Collection $comments): array
    {
        return [
            'verdict' => (string) $review->verdict,
            'riskAssessment' => $review->risk_assessment,
            'counts' => self::counts($comments),
            'summaryHtml' => Markdown::toHtml($review->summary),
            'comments' => $comments->map(fn (PrReviewComment $comment): array => [
                'severity' => $comment->severity,
                'path' => $comment->file_path,
                'line' => $comment->line_number,
                'category' => $comment->category,
                'bodyHtml' => Markdown::toHtml($comment->body),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, YakTask>  $tasks
     * @param  Collection<int, PrReview>  $reviews
     * @param  list<Run>  $runs
     * @return Panel
     */
    private static function panel(Collection $tasks, Collection $reviews, array $runs, string $repoSlug, int $prNumber): array
    {
        $newest = $tasks->first();
        $comments = $reviews->flatMap(fn (PrReview $review) => $review->comments);
        $thread = self::relatedThread($repoSlug, $prNumber);

        return [
            'repository' => $repoSlug,
            'author' => $newest === null ? null : (self::context($newest)['author'] ?? null),
            'state' => self::state($tasks, $reviews),
            'branch' => $newest?->branch_name,
            'preview' => $newest === null ? null : TaskDetailData::deployment($newest),
            'runCount' => count($runs),
            'failedCount' => count(array_filter($runs, fn (array $run): bool => $run['status'] === 'failed')),
            'totalCostUsd' => round((float) $tasks->sum(fn (YakTask $task): float => (float) $task->cost_usd), 2),
            'helpfulCount' => (int) $comments->sum('thumbs_up'),
            'notHelpfulCount' => (int) $comments->sum('thumbs_down'),
            'thread' => $thread === null ? null : ['id' => $thread->public_id, 'title' => $thread->title],
            'source' => $thread?->source_url === null ? null : ['label' => trim($thread->source->label() . ' ' . $thread->source_reference), 'url' => $thread->source_url],
        ];
    }

    /**
     * @param  Collection<int, YakTask>  $tasks
     * @param  Collection<int, PrReview>  $reviews
     */
    private static function state(Collection $tasks, Collection $reviews): string
    {
        return match (true) {
            $reviews->contains(fn (PrReview $review): bool => $review->pr_merged_at !== null) || $tasks->contains(fn (YakTask $task): bool => $task->pr_merged_at !== null) => 'merged',
            $reviews->contains(fn (PrReview $review): bool => $review->pr_closed_at !== null) || $tasks->contains(fn (YakTask $task): bool => $task->pr_closed_at !== null) => 'closed',
            default => 'open',
        };
    }

    /**
     * The thread whose task opened this pull request, through the visibility scope of the signed-in viewer, so a
     * private thread of someone else is never named.
     */
    private static function relatedThread(string $repoSlug, int $prNumber): ?Thread
    {
        $threadId = YakTask::query()
            ->where('mode', '!=', TaskMode::Review)
            ->where('repo', $repoSlug)
            ->where('pr_url', self::pullRequestUrl($repoSlug, $prNumber))
            ->whereNotNull('thread_id')
            ->orderBy('id')
            ->value('thread_id');

        return $threadId === null ? null : Thread::query()->find($threadId);
    }
}
