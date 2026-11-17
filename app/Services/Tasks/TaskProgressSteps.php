<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Models\CoOwnerRequest;
use App\Models\YakTask;

/**
 * The staged progress row of a request: Received through Done.
 */
final class TaskProgressSteps
{
    /**
     * @return array<int, array{label: string, tooltip: string, done: bool, current: bool, failed: bool}>
     */
    public static function for(YakTask $task, YakTask $head, ?CoOwnerRequest $coOwnerRequest): array
    {
        /** @var TaskStatus $status */
        $status = $head->status;

        $hasCoOwnerRequest = $coOwnerRequest !== null;
        $waitingShift = $hasCoOwnerRequest ? 1 : 0;

        $reachedStep = match ($status) {
            TaskStatus::Pending => 0,
            TaskStatus::Running, TaskStatus::AwaitingClarification, TaskStatus::Retrying => 2,
            TaskStatus::AwaitingCi => 4,
            TaskStatus::AwaitingCoOwner => 4 + $waitingShift,
            TaskStatus::Success => 6 + $waitingShift,
            TaskStatus::Failed, TaskStatus::Expired, TaskStatus::Cancelled => $task->pr_url ? 5 + $waitingShift : ($task->branch_name ? 3 : 2),
        };

        $lastCiFailed = in_array($status, [TaskStatus::Running, TaskStatus::Retrying, TaskStatus::Failed], true)
            && ($head->logs()->where('message', ProcessCIResultJob::RESULT_LOG_MESSAGE)->latest('id')->value('metadata')['passed'] ?? null) === false;
        $isRetryingAfterCiFailure = $lastCiFailed && $status !== TaskStatus::Failed;
        $isStoppedAfterCiFailure = $lastCiFailed && $status === TaskStatus::Failed;

        $ciStep = match (true) {
            $isStoppedAfterCiFailure => ['label' => 'CI failed', 'tooltip' => 'CI failed on the last push and Yak stopped after its attempts.', 'failed' => true],
            $isRetryingAfterCiFailure => ['label' => 'CI failed, retrying', 'tooltip' => 'CI failed on the last push, so Yak is fixing the changes and will push again.', 'failed' => true],
            $reachedStep === 4 => ['label' => 'Waiting for CI', 'tooltip' => 'CI verifies the changes before the pull request opens.'],
            default => ['label' => 'CI passed', 'tooltip' => 'CI verifies the changes before the pull request opens.'],
        };

        $steps = [
            ['label' => 'Received', 'tooltip' => 'Task landed in the queue.'],
            ['label' => 'Picked up', 'tooltip' => 'An agent has claimed the task and started setup.'],
            ['label' => 'Working', 'tooltip' => 'Agent is investigating the codebase and implementing the fix.'],
            ['label' => 'Pushed', 'tooltip' => 'Changes committed and pushed to a branch.'],
            $ciStep,
            ...($hasCoOwnerRequest ? [['label' => 'Waiting for a co-owner', 'tooltip' => 'A co-owner with write access has to approve before Yak opens the pull request.']] : []),
            ['label' => 'Pull request', 'tooltip' => 'Pull request opened for human review.'],
            ['label' => 'Done', 'tooltip' => 'Task complete.'],
        ];

        return array_map(fn (array $step, int $index) => [
            'label' => $step['label'],
            'tooltip' => $step['tooltip'],
            'done' => $index <= $reachedStep,
            'current' => $index === $reachedStep,
            'failed' => $step['failed'] ?? false,
        ], $steps, array_keys($steps));
    }
}
