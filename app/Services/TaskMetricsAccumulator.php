<?php

namespace App\Services;

use App\DataTransferObjects\AgentRunResult;
use App\Models\YakTask;

class TaskMetricsAccumulator
{
    public static function applyFresh(YakTask $task, AgentRunResult $result): void
    {
        $task->update([
            'session_id' => $result->sessionId,
            'cost_usd' => $result->costUsd,
            'num_turns' => $result->numTurns,
            'duration_ms' => $result->durationMs,
        ]);
    }

    public static function applyAccumulated(YakTask $task, AgentRunResult $result): void
    {
        $task->update([
            'session_id' => $result->sessionId,
            'cost_usd' => (float) $task->cost_usd + $result->costUsd,
            'num_turns' => $task->num_turns + $result->numTurns,
            'duration_ms' => $task->duration_ms + $result->durationMs,
        ]);
    }
}
