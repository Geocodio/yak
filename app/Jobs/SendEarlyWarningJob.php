<?php

namespace App\Jobs;

use App\Channels\Slack\CoOwnerMessenger;
use App\Enums\TaskStatus;
use App\Models\CoOwnerRequest;
use App\Models\YakTask;
use App\Services\CoOwner\EarlyWarning;
use App\Services\CoOwner\EarlyWarningState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Tells the requester of a new task that a co-owner will probably be needed.
 * The hint is best effort: any failure is reported and the task carries on.
 */
class SendEarlyWarningJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly YakTask $task)
    {
        $this->onQueue('default');
    }

    public function handle(EarlyWarning $earlyWarning, EarlyWarningState $state, CoOwnerMessenger $messenger): void
    {
        try {
            $task = $this->task->fresh();
            $root = $task?->rootTask();

            if ($task === null
                || $root === null
                || $root->early_warning !== null
                || ! in_array($task->status, [TaskStatus::Pending, TaskStatus::Running], true)
                || CoOwnerRequest::whereIn('task_id', $root->conversation()->pluck('id'))->exists()) {
                return;
            }

            $warning = $earlyWarning->evaluate($task);

            if ($warning === null) {
                return;
            }

            $state->start($task, $warning['areas']);
            $messenger->earlyWarning($task, $warning);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
