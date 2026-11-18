<?php

namespace App\Listeners;

use App\Channels\GitHub\AppService;
use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Models\CiFailureClaim;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\CiFailure\CiFailureNotificationGate;
use App\Services\CiFailure\CiFailureOutcome;
use App\Services\CiFailure\CiFailureTaskCreator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the end of a ci-failure task into claim state: the outcome, the
 * retry backoff, and what later failures should be suppressed by.
 */
class RecordCiFailureOutcome
{
    public function handle(TaskStatusChanged $event): void
    {
        $task = $event->task;

        if ($task->source !== CiFailureTaskCreator::SOURCE || ! in_array($event->to, [TaskStatus::Success, TaskStatus::Failed], true)) {
            return;
        }

        $transitionKey = "ci-failure-outcome:{$task->id}:{$event->to->value}:" . ($task->completed_at?->getTimestamp() ?? 0);

        if (! Cache::add($transitionKey, true, now()->addDay())) {
            return;
        }

        $claims = CiFailureClaim::query()->where('yak_task_id', $task->id)->get();

        if ($claims->isEmpty()) {
            return;
        }

        $summary = $task->result_summary;
        $isFailed = $event->to === TaskStatus::Failed;
        $outcome = $isFailed && ! CiFailureOutcome::hasLine($summary) ? CiFailureOutcome::Infrastructure : CiFailureOutcome::fromSummary($summary);
        $backsOff = $isFailed || $outcome === CiFailureOutcome::CannotFix;
        $backoff = array_values((array) config('yak.ci_failure.retry_backoff_hours'));
        $shouldNotify = false;

        foreach ($claims as $claim) {
            $updates = ['last_outcome' => $outcome->value];

            if ($backsOff) {
                $attempts = $claim->attempt_count + 1;
                $updates['attempt_count'] = $attempts;
                $updates['blocked_until'] = now()->addHours((int) $backoff[min($attempts - 1, count($backoff) - 1)]);
            }

            $shouldNotify = $shouldNotify
                || ($outcome !== CiFailureOutcome::Infrastructure && $claim->last_outcome !== $outcome->value);

            $claim->update($updates);
        }

        if ($isFailed || ! in_array($outcome, [CiFailureOutcome::Fixed, CiFailureOutcome::UnverifiedFix], true)) {
            CiFailureNotificationGate::remember($task, $shouldNotify);
        } else {
            CiFailureNotificationGate::forget($task);
        }

        match ($outcome) {
            CiFailureOutcome::AlreadyFixed => $this->recordDefaultBranchHead($task),
            CiFailureOutcome::NotShared => $this->resolveEvidence($task),
            CiFailureOutcome::CoveredByPullRequest => $this->recordCoveringPullRequest($task, $summary),
            default => null,
        };
    }

    private function recordDefaultBranchHead(YakTask $task): void
    {
        $repository = Repository::query()->where('slug', $task->repo)->first();

        if ($repository === null) {
            return;
        }

        try {
            $sha = app(AppService::class)->getBranchHeadSha(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                $repository->default_branch,
            );
        } catch (Throwable $exception) {
            report($exception);
            $sha = null;
        }

        if ($sha === null) {
            Log::channel('yak')->warning('Could not read the default branch head for an already_fixed ci-failure task', ['task_id' => $task->id]);

            return;
        }

        CiFailureClaim::query()->where('yak_task_id', $task->id)->update(['fix_merge_sha' => $sha]);
    }

    private function resolveEvidence(YakTask $task): void
    {
        $context = json_decode((string) $task->context, true);
        $jobUrls = array_values(array_filter(array_column((array) ($context['failures'] ?? []), 'job_url')));

        CiJobFailure::query()
            ->where('repo', $task->repo)
            ->whereIn('job_url', $jobUrls)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolution' => 'not_shared']);
    }

    private function recordCoveringPullRequest(YakTask $task, ?string $summary): void
    {
        $number = CiFailureOutcome::coveredPullRequestNumber($summary);
        $repository = Repository::query()->where('slug', $task->repo)->first();

        if ($number === null || $repository === null) {
            return;
        }

        CiFailureClaim::query()->where('yak_task_id', $task->id)
            ->update(['skipped_pr_url' => "https://github.com/{$repository->github_full_name}/pull/{$number}"]);
    }
}
