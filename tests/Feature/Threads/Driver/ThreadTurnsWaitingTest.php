<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\ThreadBlockReason;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

function waitingRun(TurnState $state, string $prompt): TaskRun
{
    [$thread, $task, $account] = threadWithTask();

    return TaskRun::factory()->create([
        'yak_task_id' => $task->id, 'thread_id' => $thread->id, 'claude_account_id' => $account->id,
        'turn_state' => $state, 'prompt_uuid' => fake()->uuid(), 'prompt' => $prompt, 'finished_at' => null,
    ]);
}

it('starts a waiting run once the account slot frees up', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running);
});

it('leaves a still-waiting run alone', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    $fakes = fakeTurnCollaborators(slotFree: false);
    $fakes['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::WaitingForAccount);
});

it('does not take a second account slot for a run waiting on the sandbox', function () {
    $run = waitingRun(TurnState::WaitingForSandbox, 'go');
    $run->thread->forceFill(['blocked_reason' => ThreadBlockReason::WaitingForSandbox])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['concurrency']->shouldNotReceive('acquire');
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running)
        ->and($run->thread->refresh()->blocked_reason)->toBeNull();
});

it('resumes the oldest waiting run first', function () {
    $first = waitingRun(TurnState::WaitingForAccount, 'first');
    $second = waitingRun(TurnState::WaitingForAccount, 'second');
    $fakes = fakeTurnCollaborators();
    $order = [];
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->andReturnUsing(function ($thread, $uuid, $text) use (&$order) {
        $order[] = $text;
    });

    app(ThreadTurns::class)->resumeWaiting();

    expect($order)->toBe(['first', 'second']);
});

it('moves a run waiting for an account on to the sandbox wait', function () {
    $run = waitingRun(TurnState::WaitingForAccount, 'go');
    fakeTurnCollaborators(wake: SandboxWake::restoring())['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::WaitingForSandbox);
});

it('starts a pending run once its thread is unblocked', function () {
    $run = waitingRun(TurnState::Pending, 'go');
    $run->forceFill(['started_at' => now()->subMinute()])->save();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Running);
});

it('leaves a pending run on a blocked thread alone', function () {
    $run = waitingRun(TurnState::Pending, 'go');
    $run->forceFill(['started_at' => now()->subMinute()])->save();
    $run->thread->forceFill(['blocked_reason' => ThreadBlockReason::UsageLimit])->save();
    fakeTurnCollaborators()['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Pending);
});

it('leaves a fresh pending run to start()', function () {
    $run = waitingRun(TurnState::Pending, 'go');
    $run->forceFill(['started_at' => now()])->save();
    fakeTurnCollaborators()['commands']->shouldNotReceive('turn');

    app(ThreadTurns::class)->resumeWaiting();

    expect($run->refresh()->turn_state)->toBe(TurnState::Pending);
});
