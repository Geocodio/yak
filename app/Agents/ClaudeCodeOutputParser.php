<?php

namespace App\Agents;

use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ClarificationQuestion;
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
            rawOutput: $output,
            errorSubtype: $isError ? $subtype : null,
            usage: RunUsage::fromResultEvent($decoded),
            permissionDenials: is_array($denials) ? count($denials) : 0,
            synthesized: ($decoded['synthesized'] ?? false) === true,
            wrongRepository: $wrongRepository !== null,
            wrongRepositoryReason: $wrongRepository['reason'] ?? null,
            suggestedRepository: $wrongRepository['suggested_repository'] ?? null,
            clarificationQuestions: self::extractClarificationQuestions($resultText),
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

    public const MAX_QUESTIONS = 6;

    /**
     * Questions from the last fenced `clarification` block in the result text.
     * Invalid questions and repeated ids are dropped; at most six are kept.
     *
     * @return list<ClarificationQuestion>
     */
    public static function extractClarificationQuestions(string $resultText): array
    {
        if (! preg_match_all('/```clarification\s*\n(.+?)\n```/s', $resultText, $matches) || $matches[1] === []) {
            return [];
        }

        $decoded = json_decode(trim((string) end($matches[1])), true);
        $raw = is_array($decoded) && is_array($decoded['questions'] ?? null) ? $decoded['questions'] : [];

        return collect($raw)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): ?ClarificationQuestion => ClarificationQuestion::fromArray($item))
            ->filter()
            ->unique(fn (ClarificationQuestion $question): string => $question->id)
            ->take(self::MAX_QUESTIONS)
            ->values()
            ->all();
    }

    /**
     * The result text without its clarification block, for showing as prose.
     */
    public static function stripClarificationBlock(string $resultText): string
    {
        return trim((string) preg_replace('/```clarification\s*\n.+?\n```/s', '', $resultText));
    }
}
