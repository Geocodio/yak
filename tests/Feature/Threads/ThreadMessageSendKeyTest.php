<?php

use App\Enums\SteeringMode;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

const SEND_KEY = '5f0c6a52-3c1e-4d0a-9a55-0a8f1f3b7c11';

function runningThreadForSteering(): array
{
    [$thread, $run] = runningTurnFor();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet'])->save();

    return [$thread, $run, $task];
}

it('keeps the send key on a started turn\'s message', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $task] = threadWithTask();

    app(ThreadTurns::class)->start($task, 'Do it', User::factory()->create(), null, sendKey: SEND_KEY);

    $messages = ThreadMessagesData::for($thread);
    expect($messages[0]['sendKey'])->toBe(SEND_KEY)
        ->and($messages[0]['mode'])->toBeNull();
});

it('keeps the send key on a steer and carries it to the delivered message', function () {
    [$thread] = runningThreadForSteering();
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once();

    $message = app(ThreadTurns::class)->steer($thread, 'Use the staging bucket', null, null, null, 'thread', SEND_KEY);

    expect($message->send_key)->toBe(SEND_KEY)
        ->and(ThreadTurnMessage::query()->where('uuid', $message->uuid)->value('send_key'))->toBe(SEND_KEY);
});

it('queues without delivering when asked', function () {
    [$thread] = runningThreadForSteering();
    fakeTurnCollaborators()['commands']->shouldNotReceive('steer');

    $message = app(ThreadTurns::class)->steer($thread, 'Then update the docs', null, null, null, 'thread', SEND_KEY, true);

    $row = collect(ThreadMessagesData::for($thread))->firstWhere('uuid', $message->uuid);
    expect($message->mode)->toBe(SteeringMode::Queue)
        ->and(ThreadTurnMessage::query()->where('uuid', $message->uuid)->exists())->toBeFalse()
        ->and($row)->toMatchArray(['state' => 'pending', 'mode' => 'queue', 'sendKey' => SEND_KEY]);
});

it('does not drain a delivered steer into the next prompt', function () {
    [$thread, $run, $task] = runningThreadForSteering();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('steer')->once();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    $steer = app(ThreadTurns::class)->steer($thread, 'Use the staging bucket', null, null, null, 'thread');
    $run->forceFill(['turn_state' => TurnState::Completed, 'finished_at' => now()])->save();
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();

    $next = app(ThreadTurns::class)->start($task, 'Next step', null, null);

    expect($next->prompt)->not->toContain('Use the staging bucket')
        ->and(PendingSteeringMessage::query()->whereKey($steer->id)->exists())->toBeTrue()
        ->and(array_column(ThreadMessagesData::for($thread), 'text'))->toContain('Use the staging bucket');
});

it('still drains a queued message that was never delivered', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $task] = threadWithTask();
    $queued = app(ThreadTurns::class)->steer($thread, 'Then update the docs', null, null);

    $run = app(ThreadTurns::class)->start($task, 'Next step', null, null);

    expect($run->prompt)->toContain('Then update the docs')
        ->and(PendingSteeringMessage::query()->whereKey($queued->id)->exists())->toBeFalse();
});
