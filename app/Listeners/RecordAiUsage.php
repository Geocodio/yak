<?php

namespace App\Listeners;

use App\Models\AiUsage;
use App\Services\AiPricing;
use App\Support\TaskContext;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentPrompted;

class RecordAiUsage
{
    public function handle(AgentPrompted $event): void
    {
        try {
            $response = $event->response;
            $usage = $response->usage;

            $provider = (string) ($response->meta->provider ?? $event->prompt->provider::class);
            $model = (string) ($response->meta->model ?? $event->prompt->model);

            AiUsage::create([
                'yak_task_id' => TaskContext::currentTaskId(),
                'agent_class' => $event->prompt->agent::class,
                'provider' => $provider,
                'model' => $model,
                'invocation_id' => $event->invocationId,
                'prompt_tokens' => $usage->promptTokens,
                'completion_tokens' => $usage->completionTokens,
                'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
                'cache_read_input_tokens' => $usage->cacheReadInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'cost_usd' => AiPricing::cost($provider, $model, $usage),
            ]);
        } catch (\Throwable $e) {
            // Never let cost bookkeeping break an AI call path.
            Log::channel('yak')->error('RecordAiUsage: failed to capture usage', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
