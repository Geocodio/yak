<?php

namespace App\Actions;

use App\Facades\Telemetry;
use App\Jobs\Deployments\ResolvePreviewRepairJob;
use App\Models\PreviewRepair;
use App\Models\PrReview;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\PreviewRepair\PreviewFailureReporter;
use App\Services\Threads\PullRequests\ThreadPullRequestSync;
use App\Services\Threads\ThreadCloser;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Stamp a PR's merge or close onto every task in its follow-up chain and
 * every review of it, and emit the `pr.merged` / `pr.closed` event with
 * how long the PR was open. Used by the GitHub webhook and by the hourly
 * reconciler that catches deliveries the webhook missed.
 */
class RecordPullRequestOutcome
{
    /**
     * @return bool False when nothing in Yak knows this PR.
     */
    public function record(
        string $prUrl,
        bool $merged,
        DateTimeInterface|string|null $mergedAt = null,
        DateTimeInterface|string|null $closedAt = null,
        string $via = 'webhook',
        ?int $humanCommits = null,
    ): bool {
        if ($prUrl === '') {
            return false;
        }

        $tasks = YakTask::where('pr_url', $prUrl)->orderBy('id')->get();
        $prReviews = PrReview::where('pr_url', $prUrl)->get();

        $rows = ThreadRepository::query()->where('pull_request_url', $prUrl)->get();

        if ($tasks->isEmpty() && $prReviews->isEmpty() && $rows->isEmpty()) {
            return false;
        }

        $mergedAt = $merged ? now()->parse($mergedAt ?? now()) : null;
        $closedAt = now()->parse($closedAt ?? $mergedAt ?? now());

        // Stamp the whole follow-up chain (children share the root's pr_url) so
        // the merged/closed guard (prIsOpen) is correct for every task in the
        // conversation, not just the first row found.
        $column = $merged ? 'pr_merged_at' : 'pr_closed_at';
        $stampedTaskIds = $tasks->filter(fn (YakTask $task): bool => $task->{$column} === null)->pluck('id');

        YakTask::whereIn('id', $stampedTaskIds)->update([
            $column => $merged ? $mergedAt : $closedAt,
            'pr_state_checked_at' => now(),
        ]);

        if ($humanCommits !== null) {
            YakTask::where('pr_url', $prUrl)->update(['human_commits' => $humanCommits]);
        }

        foreach ($prReviews as $review) {
            $updates = ['pr_closed_at' => $closedAt];
            if ($merged) {
                $updates['pr_merged_at'] = $mergedAt;
            }
            $review->update($updates);
        }

        $this->closeThreads($tasks, $rows, $merged, $closedAt);

        $root = $tasks->first();

        // A merged preview repair fixes the previews it was opened for, so
        // they are rebuilt, once, the first time the merge is recorded.
        if ($merged && $root !== null && $root->source === PreviewFailureReporter::SOURCE && $stampedTaskIds->contains($root->id)) {
            $previewRepairId = PreviewRepair::query()->where('yak_task_id', $root->id)->value('id');

            if ($previewRepairId !== null) {
                ResolvePreviewRepairJob::dispatch((int) $previewRepairId);
            }
        }

        // One outcome event per PR, on the task that opened it, only the
        // first time the outcome is recorded.
        if ($root !== null && $stampedTaskIds->contains($root->id)) {
            $openedAt = $root->pr_opened_at ?? $root->completed_at ?? $root->created_at;
            $endedAt = $merged ? $mergedAt : $closedAt;

            Telemetry::record($merged ? 'pr.merged' : 'pr.closed', [
                'via' => $via,
                'pr_number' => $root->pr_number,
                'follow_ups' => max(0, $tasks->count() - 1),
                'human_commits' => $humanCommits,
                'reviews' => $prReviews->count(),
                'request_to_outcome_ms' => $root->created_at !== null && $endedAt !== null
                    ? max(0, $endedAt->getTimestampMs() - $root->created_at->getTimestampMs())
                    : null,
            ], task: $root, durationMs: $openedAt !== null && $endedAt !== null
                ? max(0, $endedAt->getTimestampMs() - $openedAt->getTimestampMs())
                : null);
        }

        return true;
    }

    /**
     * Records the outcome on the matching repo rows and closes each thread
     * once none of its pull requests is still open. A thread whose task has
     * no repo row (older data) closes straight away.
     *
     * @param  Collection<int, YakTask>  $tasks
     * @param  Collection<int, ThreadRepository>  $rows
     */
    private function closeThreads(Collection $tasks, Collection $rows, bool $merged, CarbonInterface $closedAt): void
    {
        $sync = app(ThreadPullRequestSync::class);
        $closer = app(ThreadCloser::class);

        foreach ($rows as $row) {
            $sync->recordOutcome($row, $merged, $closedAt);
        }

        $rowThreadIds = $rows->pluck('thread_id');
        $threadIds = $tasks->pluck('thread_id')->merge($rowThreadIds)->filter()->unique();

        Thread::withoutGlobalScopes()->whereIn('id', $threadIds)->get()->each(function (Thread $thread) use ($sync, $closer, $rowThreadIds, $merged): void {
            if ($rowThreadIds->contains($thread->id)) {
                $sync->closeThreadIfAllEnded($thread);

                return;
            }

            $closer->close($thread, $merged ? 'pr_merged' : 'pr_closed');
        });
    }
}
