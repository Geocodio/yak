<?php

namespace App\Jobs\Concerns;

use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RepoClarificationResolver;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;

/**
 * Handles an agent's verdict that the checkout it was given is not where the
 * requested change lives.
 *
 * The task goes back to the state of an unresolved intake, so the next reply
 * picks a repository and RunYakJob starts over from scratch. A branch pushed
 * by an earlier attempt stays on the remote and is skipped when the next run
 * picks its branch name.
 *
 * The consuming job must expose a `$task` property typed to YakTask.
 */
trait HandlesWrongRepository
{
    /**
     * Returns false, leaving the task untouched, when there is no other
     * active repository to offer or the task already has a pull request
     * (which pins it to this repository).
     */
    private function handleWrongRepository(Repository $repository, AgentRunResult $result, bool $countsAsNewTask = true): bool
    {
        if ($this->task->pr_url !== null) {
            return false;
        }

        /** @var list<string> $options */
        $options = Repository::where('is_active', true)
            ->where('slug', '!=', $repository->slug)
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        if ($options === []) {
            return false;
        }

        TaskMetricsAccumulator::record($this->task, $result);
        DailyCost::accumulate($result->costUsd, newTask: $countsAsNewTask);

        $suggested = $result->suggestedRepository;

        if ($suggested !== null && in_array($suggested, $options, true)) {
            $options = [$suggested, ...array_values(array_diff($options, [$suggested]))];
        }

        $reason = $result->wrongRepositoryReason ?? '';

        TaskLogger::info($this->task, "Agent reported the wrong repository ({$repository->slug})", [
            'reason' => $reason,
            'suggested_repository' => $suggested,
        ]);

        $this->task->update([
            'repo' => 'unknown',
            'session_id' => null,
            'branch_name' => null,
            'status' => TaskStatus::AwaitingClarification,
            'clarification_options' => $options,
            ...YakTask::clarificationDeadlines(),
        ]);

        $explanation = $reason !== '' ? " {$reason}" : '';

        SendNotificationJob::dispatch(
            $this->task,
            NotificationType::Clarification,
            "I looked in {$repository->slug}, but this doesn't seem to belong there.{$explanation} Which repo should I work in? Reply with a number:\n" . RepoClarificationResolver::numberedList($options),
        );

        return true;
    }
}
