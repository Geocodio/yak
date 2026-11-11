<?php

namespace App\Jobs\Concerns;

use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\SessionStore\SessionSync;
use App\Services\TaskLogger;
use App\Services\Telemetry\RunRecorder;
use App\Services\YakPersonality;

/**
 * A teleport task carries a laptop's work onto Yak without a pull request: its
 * turn ends with the result recorded and the session synced, and nothing is
 * pushed or waited on in CI. Once a pull request exists the normal path runs.
 *
 * @property YakTask $task
 */
trait CompletesTeleportTurns
{
    private function isUnpublishedTeleportTask(): bool
    {
        return $this->task->isTeleport() && $this->task->pr_url === null;
    }

    private function completeTeleportTurn(AgentRunResult $result, RunRecorder $recorder): void
    {
        $thread = $this->task->thread_id === null ? null : Thread::query()->find($this->task->thread_id);

        if ($thread !== null) {
            app(SessionSync::class)->syncFromSandbox($thread);
        }

        $recorder->noChanges();

        $this->task->update([
            'status' => TaskStatus::Success,
            'completed_at' => now(),
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
        ]);

        TaskLogger::info($this->task, 'Teleport turn finished without a push');

        $message = YakPersonality::generate(NotificationType::Result, $result->resultSummary);
        SendNotificationJob::dispatch($this->task, NotificationType::Result, $message);
    }
}
