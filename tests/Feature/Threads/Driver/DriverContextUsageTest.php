<?php

use App\Models\ThreadSession;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ReplayFixture.php';

function contextUsageOfLastAssistantLine(string $fixture): array
{
    $usage = null;

    foreach (file(base_path("tests/Fixtures/runner-streams/{$fixture}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode($raw, true);

        if (($line['type'] ?? null) === 'assistant' && ($line['parent_tool_use_id'] ?? null) === null) {
            $usage = $line['message']['usage'];
        }
    }

    return [
        'input' => $usage['input_tokens'],
        'cacheRead' => $usage['cache_read_input_tokens'],
        'cacheCreation' => $usage['cache_creation_input_tokens'],
    ];
}

it('stores the input side of the latest assistant message on the session', function () {
    $uuid = 'f429399e-374d-5e1e-a7cc-65fce3bd2a28';
    [$thread, $run] = runningTurnFor(['prompt_uuid' => $uuid, 'prompt' => 'scrubbed text 1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => $uuid]);
    ThreadTurns::trackUuid($run, $uuid);

    replayFixtureThroughConsumer($thread, 'natural-turn-end');

    $session = ThreadSession::query()->where('thread_id', $thread->id)->sole();

    expect($session->context_usage)->toBe(contextUsageOfLastAssistantLine('natural-turn-end'))
        ->and(array_sum($session->context_usage))->toBeGreaterThan(0);
});

it('leaves a new session unmeasured until it answers', function () {
    $uuid = 'f429399e-374d-5e1e-a7cc-65fce3bd2a28';
    [$thread, $run] = runningTurnFor(['prompt_uuid' => $uuid, 'prompt' => 'scrubbed text 1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => $uuid]);
    ThreadTurns::trackUuid($run, $uuid);
    replayFixtureThroughConsumer($thread, 'natural-turn-end');

    $fresh = ThreadSession::factory()->create(['thread_id' => $thread->id]);

    expect($thread->activeSession()->is($fresh))->toBeTrue()
        ->and($thread->activeSession()->context_usage)->toBeNull();
});
