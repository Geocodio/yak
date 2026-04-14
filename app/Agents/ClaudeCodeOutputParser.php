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

        $resultText = (string) ($decoded['result'] ?? $decoded['result_summary'] ?? '');

        // Check for clarification at top level first, then in the result text
        $clarification = self::extractClarification($decoded, $resultText);

        return new AgentRunResult(
            sessionId: (string) ($decoded['session_id'] ?? ''),
            resultSummary: $resultText,
            costUsd: (float) ($decoded['total_cost_usd'] ?? $decoded['cost_usd'] ?? 0),
            numTurns: (int) ($decoded['num_turns'] ?? 0),
            durationMs: (int) ($decoded['duration_ms'] ?? 0),
            isError: ($decoded['is_error'] ?? false) === true,
            clarificationNeeded: $clarification['needed'],
            clarificationOptions: $clarification['options'],
            rawOutput: $output,
        );
    }

    /**
     * Extract clarification from top-level keys or from embedded JSON in the result text.
     *
     * The agent may return clarification as a top-level key or as a JSON code block
     * inside the result text (per the prompt instructions).
     *
     * @param  array<string, mixed>  $decoded
     * @return array{needed: bool, options: list<string>}
     */
    private static function extractClarification(array $decoded, string $resultText): array
    {
        // Top-level clarification (e.g. from a custom agent format)
        if (($decoded['clarification_needed'] ?? false) === true) {
            $options = $decoded['options'] ?? [];

            return [
                'needed' => true,
                'options' => is_array($options) ? array_values(array_map('strval', $options)) : [],
            ];
        }

        // Check if the result text contains a JSON block with clarification_needed
        if (preg_match('/\{[^{}]*"clarification_needed"\s*:\s*true[^{}]*\}/s', $resultText, $match)) {
            /** @var array{clarification_needed?: bool, options?: list<string>}|null $embedded */
            $embedded = json_decode($match[0], true);

            if (is_array($embedded) && ($embedded['clarification_needed'] ?? false) === true) {
                $options = $embedded['options'] ?? [];

                return [
                    'needed' => true,
                    'options' => array_map('strval', $options),
                ];
            }
        }

        return ['needed' => false, 'options' => []];
    }
}
