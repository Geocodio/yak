<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\TurnFailed;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

it('interrupts a running turn once', function () {
    Event::fake([TurnFailed::class]);
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'p-1']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'p-1']);
    $fakes = fakeTurnCollaborators();
    $fakes['concurrency']->shouldReceive('release')->once();
    $fakes['commands']->shouldReceive('interrupt')->once();

    app(ThreadTurns::class)->stop($thread);
    app(ThreadTurns::class)->stop($thread);

    expect($run->refresh()->turn_state)->toBe(TurnState::Interrupted)
        ->and($run->completion_event_emitted_at)->not->toBeNull()
        ->and($thread->refresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and(ThreadTurnMessage::query()->where('uuid', 'p-1')->value('state'))->toBe(TurnMessageState::Cancelled);
    Event::assertDispatchedTimes(TurnFailed::class, 1);
});

it('cancels a waiting turn without a runner command', function () {
    Event::fake([TurnFailed::class]);
    [$thread, $run] = runningTurnFor(['turn_state' => TurnState::WaitingForAccount]);
    $thread->forceFill(['blocked_reason' => ThreadBlockReason::WaitingForAccount])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldNotReceive('interrupt');
    $fakes['concurrency']->shouldNotReceive('release');

    app(ThreadTurns::class)->stop($thread);

    expect($run->refresh()->turn_state)->toBe(TurnState::Interrupted)
        ->and($thread->refresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($thread->blocked_reason)->toBeNull();
    Event::assertDispatchedTimes(TurnFailed::class, 1);
});

it('does nothing when idle', function () {
    Event::fake([TurnFailed::class]);
    [$thread] = threadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldNotReceive('interrupt');

    app(ThreadTurns::class)->stop($thread);

    Event::assertNotDispatched(TurnFailed::class);
});
