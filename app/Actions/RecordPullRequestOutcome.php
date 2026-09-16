<?php

namespace App\Actions;

use App\Facades\Telemetry;
use App\Models\PrReview;
use App\Models\YakTask;
use DateTimeInterface;

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

        if ($tasks->isEmpty() && $prReviews->isEmpty()) {
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

        // One outcome event per PR, on the task that opened it, only the
        // first time the outcome is recorded.
        $root = $tasks->first();
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
}
