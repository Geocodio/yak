<?php

use App\Enums\RunnerState;
use App\Models\TaskRun;
use App\Models\ThreadSession;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Str;

require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

function pendingRunFor($thread, $task): TaskRun
{
    return TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'claude_account_id' => $thread->claude_account_id,
        'turn_state' => 'pending',
        'prompt_uuid' => (string) Str::uuid(),
        'prompt' => 'go',
    ]);
}

it('starts the runner without a resume session when fresh_session_pending is set, then clears the flag', function () {
    [$thread, $task] = threadWithTask();
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => 'old-session']);
    $thread->forceFill(['fresh_session_pending' => true])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner')->once()->withArgs(fn ($t, $a, $resume) => $resume === null);
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->dispatchTurn($thread, pendingRunFor($thread, $task));

    expect($thread->refresh()->fresh_session_pending)->toBeFalse();
});

it('resumes the active session without the flag', function () {
    [$thread, $task] = threadWithTask();
    ThreadSession::factory()->create(['thread_id' => $thread->id, 'session_id' => 'old-session']);
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner')->once()->withArgs(fn ($t, $a, $resume) => $resume === 'old-session');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->dispatchTurn($thread, pendingRunFor($thread, $task));
});

it('does not restart a live runner on the flag alone', function () {
    [$thread, $task] = threadWithTask();
    $thread->forceFill(['fresh_session_pending' => true, 'runner_state' => RunnerState::Idle, 'runner_model' => 'sonnet'])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldNotReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->dispatchTurn($thread, pendingRunFor($thread, $task));
});
