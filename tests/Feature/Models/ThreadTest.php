<?php

use App\Enums\CommandState;
use App\Enums\RunnerState;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadSession;

it('returns the latest main session as the active session', function () {
    $thread = Thread::factory()->create();
    ThreadSession::factory()->for($thread)->create(['session_id' => 's1']);
    ThreadSession::factory()->for($thread)->create(['session_id' => 's2', 'kind' => ThreadSessionKind::Clear]);
    ThreadSession::factory()->for($thread)->create(['session_id' => 's3']);

    expect($thread->activeSession()->session_id)->toBe('s3');
});

it('finds the running turn', function () {
    $thread = Thread::factory()->create();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);
    $running = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    expect($thread->runningTurn()->id)->toBe($running->id);
});

it('casts command states to enums', function () {
    $run = TaskRun::factory()->create(['command_states' => ['u1' => 'sent', 'u2' => 'completed']]);

    $states = $run->fresh()->command_states;

    expect($states['u1'])->toBe(CommandState::Sent)
        ->and($states['u2'])->toBe(CommandState::Completed);
});

it('names the thread channel', function () {
    expect(Thread::factory()->create(['id' => 42])->channel())->toBe('threads:42');
});

it('defaults new threads to open with no sandbox and no runner', function () {
    $thread = Thread::factory()->create()->fresh();

    expect($thread->isOpen())->toBeTrue()
        ->and($thread->status)->toBe(ThreadStatus::Open)
        ->and($thread->sandbox_state)->toBe(ThreadSandboxState::None)
        ->and($thread->runner_state)->toBe(RunnerState::None)
        ->and($thread->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($thread->runner_generation)->toBe(0);
});
