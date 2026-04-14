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
        /** @var array<string, float>|null $rates */
        $rates = config("ai-pricing.providers.{$provider}.{$model}");

        if (! is_array($rates)) {
            Log::channel('yak')->warning('AiPricing: unknown model, cost recorded as 0', [
                'provider' => $provider,
                'model' => $model,
            ]);

            return 0.0;
        }

        // Cache-read/write tokens are reported as separate counters but are
        // typically included in prompt_tokens by the Anthropic API. Subtract
        // them so we don't double-charge.
        $cachedInput = $usage->cacheReadInputTokens + $usage->cacheWriteInputTokens;
        $plainInputTokens = max(0, $usage->promptTokens - $cachedInput);

        $million = 1_000_000;

        $cost = ($plainInputTokens / $million) * (float) ($rates['input'] ?? 0)
            + ($usage->completionTokens / $million) * (float) ($rates['output'] ?? 0)
            + ($usage->cacheWriteInputTokens / $million) * (float) ($rates['cache_write'] ?? 0)
            + ($usage->cacheReadInputTokens / $million) * (float) ($rates['cache_read'] ?? 0)
            + ($usage->reasoningTokens / $million) * (float) ($rates['output'] ?? 0);

        return round($cost, 6);
    }
}
