<?php

namespace App\Services\Threads;

/**
 * Per-turn cost and token accounting from a runner `result` line.
 */
final class TurnCost
{
    /**
     * @param  array<string, mixed>  $result
     */
    public static function deltaUsd(array $result, float $recordedSessionTotalUsd): float
    {
        $sinceLastResult = $result['model_usage_since_last_result'] ?? null;

        if (is_array($sinceLastResult) && $sinceLastResult !== []) {
            return round(array_sum(array_map(fn ($usage) => (float) ($usage['costUSD'] ?? 0), $sinceLastResult)), 6);
        }

        if (! isset($result['total_cost_usd'])) {
            return 0.0;
        }

        $total = (float) $result['total_cost_usd'];

        return round($total >= $recordedSessionTotalUsd ? $total - $recordedSessionTotalUsd : $total, 6);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function sessionTotalUsd(array $result, float $recorded): float
    {
        return isset($result['total_cost_usd']) ? (float) $result['total_cost_usd'] : $recorded;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{input: int, output: int, cache_read: int, cache_creation: int}
     */
    public static function tokens(array $result): array
    {
        $usage = $result['usage'] ?? [];
        $tokens = [
            'input' => (int) ($usage['input_tokens'] ?? 0),
            'output' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
        ];

        if (array_sum($tokens) > 0) {
            return $tokens;
        }

        $fromModelUsage = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];

        foreach (($result['modelUsage'] ?? []) as $usage) {
            $fromModelUsage['input'] += (int) ($usage['inputTokens'] ?? 0);
            $fromModelUsage['output'] += (int) ($usage['outputTokens'] ?? 0);
            $fromModelUsage['cache_read'] += (int) ($usage['cacheReadInputTokens'] ?? 0);
            $fromModelUsage['cache_creation'] += (int) ($usage['cacheCreationInputTokens'] ?? 0);
        }

        return $fromModelUsage;
    }
}
