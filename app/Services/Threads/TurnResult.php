<?php

namespace App\Services\Threads;

use App\Agents\ClaudeCodeOutputParser;
use App\DataTransferObjects\AgentRunResult;
use App\Models\TaskRun;

/**
 * Rebuilds the result the CLI path used to return from the turn's recorded
 * result event, so the after-turn code reads one shape.
 */
final class TurnResult
{
    public static function toAgentRunResult(TaskRun $run): AgentRunResult
    {
        $durationMs = $run->agent_started_at !== null && $run->finished_at !== null
            ? (int) $run->agent_started_at->diffInMilliseconds($run->finished_at, true)
            : 0;

        return self::fromEvent($run->last_result_event, (int) $run->assistant_turns, $durationMs, (float) $run->recorded_cost_usd);
    }

    /**
     * @param  array<string, mixed>|mixed  $event  the last `result` line of the turn
     */
    public static function fromEvent(mixed $event, int $assistantMessages, int $durationMs, float $costUsd): AgentRunResult
    {
        if (! is_array($event)) {
            return AgentRunResult::failure('The runner ended the turn without a result', '{}');
        }

        $event['num_turns'] = $assistantMessages;
        $event['duration_ms'] = $durationMs;
        $event['total_cost_usd'] = $costUsd;

        return ClaudeCodeOutputParser::parse(json_encode($event, JSON_THROW_ON_ERROR));
    }
}
