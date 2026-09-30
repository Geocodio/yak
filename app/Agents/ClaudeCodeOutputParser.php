<?php

namespace App\Agents;

use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\RunUsage;

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

        $wrongRepository = self::extractWrongRepository($resultText);

        $isError = ($decoded['is_error'] ?? false) === true;
        $subtype = isset($decoded['subtype']) ? (string) $decoded['subtype'] : null;
        $denials = $decoded['permission_denials'] ?? [];

        return new AgentRunResult(
            sessionId: (string) ($decoded['session_id'] ?? ''),
            resultSummary: $resultText,
            costUsd: (float) ($decoded['total_cost_usd'] ?? $decoded['cost_usd'] ?? 0),
            numTurns: (int) ($decoded['num_turns'] ?? 0),
            durationMs: (int) ($decoded['duration_ms'] ?? 0),
            isError: $isError,
            clarificationNeeded: $clarification['needed'],
            clarificationOptions: $clarification['options'],
            rawOutput: $output,
            errorSubtype: $isError ? $subtype : null,
            usage: RunUsage::fromResultEvent($decoded),
            permissionDenials: is_array($denials) ? count($denials) : 0,
            synthesized: ($decoded['synthesized'] ?? false) === true,
            wrongRepository: $wrongRepository !== null,
            wrongRepositoryReason: $wrongRepository['reason'] ?? null,
            suggestedRepository: $wrongRepository['suggested_repository'] ?? null,
        );
    }

    /**
     * Extract a preview manifest from a fenced preview_manifest code block in the result text.
     *
     * The agent emits the manifest as a fenced code block tagged `preview_manifest` at the
     * end of its response. Returns the decoded array, or null if absent or malformed.
     *
     * @return array<string, mixed>|null
     */
    public static function extractPreviewManifest(string $resultText): ?array
    {
        if (! preg_match('/```preview_manifest\s*\n(.+?)\n```/s', $resultText, $m)) {
            return null;
        }

        $decoded = json_decode(trim($m[1]), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Extract the wrong-repository verdict from a fenced wrong_repository code block.
     *
     * The agent emits the block when it concludes the requested change belongs in a
     * different repository than the checkout it was given. The JSON carries a short
     * `reason` and an optional `suggested_repository` slug. Returns null when the block
     * is absent or is not valid JSON.
     *
     * @return array{reason: string, suggested_repository: string|null}|null
     */
    public static function extractWrongRepository(string $resultText): ?array
    {
        if (! preg_match('/```wrong_repository\s*\n(.+?)\n```/s', $resultText, $m)) {
            return null;
        }

        $decoded = json_decode(trim($m[1]), true);

        if (! is_array($decoded)) {
            return null;
        }

        $suggested = $decoded['suggested_repository'] ?? null;

        return [
            'reason' => trim((string) ($decoded['reason'] ?? '')),
            'suggested_repository' => is_string($suggested) && trim($suggested) !== '' ? trim($suggested) : null,
        ];
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
