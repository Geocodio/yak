<?php

namespace App\Services\Threads;

use App\Agents\ClaudeCodeOutputParser;
use App\DataTransferObjects\AgentRunResult;
use App\Models\TaskRun;

/**
 * Rebuilds the result the CLI path used to return from the turn's recorded
 * result event, so the after-turn code reads the same shape for both paths.
 */
final class TurnResult
{
    public static function toAgentRunResult(TaskRun $run): AgentRunResult
    {
        return self::fromEvent($run->last_result_event, (int) $run->assistant_messages, (int) $run->agent_ms, (float) $run->cost_usd);
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

        return ClaudeCodeOutputParser::parse(json_encode($event, JSON_THROW_ON_ERROR))
            ->withIncrementalCost($costUsd);
    }
}
