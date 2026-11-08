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
        $event = $run->last_result_event;

        if (! is_array($event)) {
            return AgentRunResult::failure('The runner ended the turn without a result', '{}');
        }

        $event['num_turns'] = (int) $run->assistant_messages;
        $event['duration_ms'] = (int) $run->agent_ms;

        return ClaudeCodeOutputParser::parse(json_encode($event, JSON_THROW_ON_ERROR))
            ->withIncrementalCost((float) $run->cost_usd);
    }
}
