<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;

class AiPricing
{
    /**
     * Compute USD cost for the given usage against the configured rates.
     * Returns 0.0 (and logs) when the model is unknown.
     */
    public static function cost(string $provider, string $model, Usage $usage): float
    {
        // Cache-read/write tokens are reported as separate counters but are
        // typically included in prompt_tokens by the Anthropic API. Subtract
        // them so we don't double-charge.
        $cachedInput = $usage->cacheReadInputTokens + $usage->cacheWriteInputTokens;
        $plainInputTokens = max(0, $usage->promptTokens - $cachedInput);

        return self::costForTokens(
            $provider,
            $model,
            inputTokens: $plainInputTokens,
            outputTokens: $usage->completionTokens,
            cacheWriteTokens: $usage->cacheWriteInputTokens,
            cacheReadTokens: $usage->cacheReadInputTokens,
            reasoningTokens: $usage->reasoningTokens,
        );
    }

    /**
     * Cost for raw token counts, where `inputTokens` already excludes
     * cached input (the shape the Claude Code stream reports). Used to
     * price a run whose `result` event never arrived.
     */
    public static function costForTokens(
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
        int $reasoningTokens = 0,
    ): float {
        /** @var array<string, float>|null $rates */
        $rates = config("ai-pricing.providers.{$provider}.{$model}");

        if (! is_array($rates)) {
            Log::channel('yak')->warning('AiPricing: unknown model, cost recorded as 0', [
                'provider' => $provider,
                'model' => $model,
            ]);

            return 0.0;
        }

        $million = 1_000_000;

        $cost = ($inputTokens / $million) * (float) ($rates['input'] ?? 0)
            + ($outputTokens / $million) * (float) ($rates['output'] ?? 0)
            + ($cacheWriteTokens / $million) * (float) ($rates['cache_write'] ?? 0)
            + ($cacheReadTokens / $million) * (float) ($rates['cache_read'] ?? 0)
            + ($reasoningTokens / $million) * (float) ($rates['output'] ?? 0);

        return round($cost, 6);
    }
}
