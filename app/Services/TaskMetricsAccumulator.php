<?php

namespace App\Services;

use App\DataTransferObjects\AgentRunResult;
use App\Models\YakTask;

/**
 * Lifetime totals on the task row. Every agent run adds to cost, turns
 * and duration -- the initial run, a CI retry, a clarification reply and
 * a dashboard re-run alike -- so `tasks.cost_usd` is what the task cost
 * in total. The per-run breakdown lives in task_runs (see RunRecorder).
 *
 * Earlier the initial run replaced the totals and the dashboard retry
 * zeroed them, which threw away the cost of every previous attempt.
 */
class TaskMetricsAccumulator
{
    public static function record(YakTask $task, AgentRunResult $result): void
    {
        $task->update([
            'session_id' => $result->sessionId !== '' ? $result->sessionId : $task->session_id,
            'cost_usd' => (float) $task->cost_usd + $result->costUsd,
            'num_turns' => (int) $task->num_turns + $result->numTurns,
            'duration_ms' => (int) $task->duration_ms + $result->durationMs,
        ]);
    }
}
