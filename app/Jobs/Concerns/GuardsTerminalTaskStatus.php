<?php

namespace App\Jobs\Concerns;

use App\Enums\TaskStatus;
use App\Models\YakTask;

/**
 * Shared terminal-status guard for job code that writes `status` /
 * `error_log` directly, outside `HandlesAgentJobFailure::failed()`.
 *
 * A task can already be terminal by the time an inline error handler
 * runs — cancelled between dispatch and pickup, force-failed by a
 * deploy drain, or finalised by a parallel path — and an unconditional
 * write would overwrite an accurate message (a drain reason, a
 * cancellation) with a misleading one (a stream-ended / SIGKILL
 * artifact of whatever interrupted the run).
 */
trait GuardsTerminalTaskStatus
{
    /**
     * @return array<int, TaskStatus>
     */
    private function terminalTaskStatuses(): array
    {
        return [TaskStatus::Success, TaskStatus::Failed, TaskStatus::Expired, TaskStatus::Cancelled];
    }

    private function taskIsTerminal(?YakTask $task): bool
    {
        return $task !== null && in_array($task->status, $this->terminalTaskStatuses(), true);
    }
}
