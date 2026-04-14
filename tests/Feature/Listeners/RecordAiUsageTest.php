<?php

use App\Ai\Agents\PersonalityAgent;
use App\Listeners\RecordAiUsage;
use App\Models\AiUsage;
use App\Models\YakTask;
use App\Support\TaskContext;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

function makeAgentPromptedEvent(?YakTask $task = null, string $model = 'claude-haiku-4-5-20251001'): AgentPrompted
{
    $agent = new PersonalityAgent('acknowledgment', 'hi');
    $provider = Mockery::mock(TextProvider::class);

    $prompt = new AgentPrompt(
        agent: $agent,
        prompt: 'Generate the notification message.',
        attachments: new Collection,
        provider: $provider,
        model: $model,
    );

    $usage = new Usage(
        promptTokens: 1000,
        completionTokens: 500,
    );

    $response = new AgentResponse(
        invocationId: 'inv_test_123',
        text: 'On it!',
        usage: $usage,
        meta: new Meta(provider: 'anthropic', model: $model),
    );

    return new AgentPrompted('inv_test_123', $prompt, $response);
}

afterEach(function () {
    TaskContext::clear();
});

test('records usage with task attribution', function () {
    $task = YakTask::factory()->create();
    TaskContext::set($task);

    app(RecordAiUsage::class)->handle(makeAgentPromptedEvent());

    $row = AiUsage::latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->yak_task_id)->toBe($task->id);
    expect($row->model)->toBe('claude-haiku-4-5-20251001');
    expect($row->provider)->toBe('anthropic');
    expect($row->agent_class)->toBe(PersonalityAgent::class);
    expect($row->prompt_tokens)->toBe(1000);
    expect($row->completion_tokens)->toBe(500);
    expect($row->invocation_id)->toBe('inv_test_123');
    // input = 1000 / 1M * 1.00 = 0.001
    // output = 500 / 1M * 5.00 = 0.0025
    // total = 0.0035
    expect((float) $row->cost_usd)->toBe(0.0035);
});

test('records usage without task when context is empty', function () {
    app(RecordAiUsage::class)->handle(makeAgentPromptedEvent());

    $row = AiUsage::latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->yak_task_id)->toBeNull();
});

test('records zero cost and still persists row for unknown model', function () {
    app(RecordAiUsage::class)->handle(makeAgentPromptedEvent(model: 'claude-not-a-model'));

    $row = AiUsage::latest('id')->first();
    expect($row->model)->toBe('claude-not-a-model');
    expect((float) $row->cost_usd)->toBe(0.0);
    expect($row->prompt_tokens)->toBe(1000);
});

test('listener is registered for AgentPrompted event', function () {
    $listeners = app('events')->getRawListeners()[AgentPrompted::class] ?? [];
    expect($listeners)->toContain(RecordAiUsage::class);
});
