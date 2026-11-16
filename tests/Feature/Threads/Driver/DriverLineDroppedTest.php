<?php

use App\Enums\TurnState;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;

it('logs a warning for a dropped oversized line and keeps the entry applied', function () {
    $thread = Thread::factory()->create();
    $thread->forceFill(['runner_generation' => 1])->save();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $entry = app(BrokerStreams::class)->pushOut('threads:' . $thread->id, 1, 1, ['type' => 'broker.line_dropped', 'bytes' => 9000000], fromBroker: true);

    app(ThreadDriver::class)->handle($entry);

    $log = TaskLog::query()->where('level', 'warning')->where('message', 'runner_line_dropped')->first();
    expect($log)->not->toBeNull()
        ->and($log->metadata)->toMatchArray(['bytes' => 9000000])
        ->and($thread->fresh()->applied_sequence)->toBe(1)
        ->and($run->fresh()->turn_state)->toBe(TurnState::Running);
});
