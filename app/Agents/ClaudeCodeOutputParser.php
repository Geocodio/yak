<?php

namespace App\Agents;

use App\DataTransferObjects\AgentRunResult;

class ClaudeCodeOutputParser
{
    public static function parse(string $output): AgentRunResult
    {
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return AgentRunResult::failure('Claude Code returned malformed output', $output);
        }

        $options = $decoded['options'] ?? [];
        $clarificationOptions = is_array($options) ? array_values(array_map('strval', $options)) : [];

        return new AgentRunResult(
            sessionId: (string) ($decoded['session_id'] ?? ''),
            resultSummary: (string) ($decoded['result'] ?? $decoded['result_summary'] ?? ''),
            costUsd: (float) ($decoded['total_cost_usd'] ?? $decoded['cost_usd'] ?? 0),
            numTurns: (int) ($decoded['num_turns'] ?? 0),
            durationMs: (int) ($decoded['duration_ms'] ?? 0),
            isError: ($decoded['is_error'] ?? false) === true,
            clarificationNeeded: ($decoded['clarification_needed'] ?? false) === true,
            clarificationOptions: $clarificationOptions,
            rawOutput: $output,
        );
    }
}
