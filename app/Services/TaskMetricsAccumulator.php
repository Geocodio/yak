<?php

namespace App\Services;

use App\DataTransferObjects\AgentRunResult;
use App\Models\TaskRun;
use App\Models\YakTask;

/**
 * Lifetime totals on the task row. Every agent run adds to cost, turns
 * and duration -- the initial run, a CI retry, a clarification reply and
 * a dashboard re-run alike -- so `tasks.cost_usd` is what the task cost
 * in total. The per-run breakdown lives in task_runs (see RunRecorder).
 *
 * `total_cost_usd` is cumulative per Claude session, so a run that resumes
 * the task's session reports everything the session has cost so far. Only
 * the part above what earlier runs of that session already reported is new.
 */
class TaskMetricsAccumulator
{
    public static function record(YakTask $task, AgentRunResult $result): void
    {
        $task->update([
            'session_id' => $result->sessionId !== '' ? $result->sessionId : $task->session_id,
            'cost_usd' => (float) $task->cost_usd + self::newCost($task, $result),
            'num_turns' => (int) $task->num_turns + $result->numTurns,
            'duration_ms' => (int) $task->duration_ms + $result->durationMs,
        ]);
    }

    private static function newCost(YakTask $task, AgentRunResult $result): float
    {
        if ($result->sessionId === '' || $result->sessionId !== $task->session_id) {
            return $result->costUsd;
        }

        $reportedBefore = (float) TaskRun::query()
            ->where('yak_task_id', $task->id)
            ->where('session_id', $result->sessionId)
            ->where('cost_usd', '<', $result->costUsd)
            ->max('cost_usd');

        return max(0.0, $result->costUsd - $reportedBefore);
    }
}
