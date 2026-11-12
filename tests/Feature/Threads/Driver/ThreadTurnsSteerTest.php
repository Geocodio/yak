<?php

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\SteeringText;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

function runningThreadWithTask(): array
{
    [$thread, $run] = runningTurnFor();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet'])->save();

    return [$thread, $run, $task];
}

it('sends a steer to a running turn with a uuid', function () {
    [$thread, $run] = runningThreadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('steer')->once()->with(Mockery::any(), Mockery::type('string'), SteeringText::PREAMBLE . 'also the footer');

    $message = app(ThreadTurns::class)->steer($thread, 'also the footer', User::factory()->create(['name' => 'Ada']), null, null, 'thread');

    $turnMessage = ThreadTurnMessage::query()->where('uuid', $message->uuid)->first();
    expect($message->mode)->toBe(SteeringMode::Steer)
        ->and($message->author_name)->toBe('Ada')
        ->and($message->source)->toBe('thread')
        ->and($turnMessage->task_run_id)->toBe($run->id)
        ->and($turnMessage->kind)->toBe(TurnMessageKind::Steer)
        ->and($turnMessage->state)->toBe(TurnMessageState::Sent)
        ->and($turnMessage->pending_steering_message_id)->toBe($message->id);
});

it('queues when no turn runs', function () {
    [$thread, $task] = threadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldNotReceive('steer');

    $message = app(ThreadTurns::class)->steer($thread, 'later', null, null);

    expect($message->mode)->toBe(SteeringMode::Queue)
        ->and($message->root_task_id)->toBe($task->id)
        ->and(ThreadTurnMessage::query()->count())->toBe(0);
});

it('queues when the runner is not ready', function () {
    [$thread] = runningThreadWithTask();
    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();
    fakeTurnCollaborators()['commands']->shouldNotReceive('steer');

    expect(app(ThreadTurns::class)->steer($thread, 'later', null, null)->mode)->toBe(SteeringMode::Queue);
});

it('sends the model before the steer when it changed', function () {
    [$thread] = runningThreadWithTask();
    $fakes = fakeTurnCollaborators();
    $order = [];
    $fakes['commands']->shouldReceive('setModel')->once()->with(Mockery::any(), 'opus')->andReturnUsing(function () use (&$order) {
        $order[] = 'set_model';
    });
    $fakes['commands']->shouldReceive('steer')->once()->andReturnUsing(function () use (&$order) {
        $order[] = 'steer';
    });

    app(ThreadTurns::class)->steer($thread, 'think harder', null, 'opus');

    expect($order)->toBe(['set_model', 'steer']);
});

it('does not wrap a steer Yak framed itself', function () {
    [$thread] = runningThreadWithTask();
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once()->with(Mockery::any(), Mockery::type('string'), 'already framed');

    $message = app(ThreadTurns::class)->steer($thread, 'already framed', null, null);

    expect($message->source)->toBe('system');
});

it('runs the attachment callback before delivery', function () {
    [$thread] = runningThreadWithTask();
    $order = [];
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once()->andReturnUsing(function () use (&$order) {
        $order[] = 'steer';
    });

    app(ThreadTurns::class)->steer($thread, 'x', null, null, function ($message) use (&$order) {
        $order[] = 'store';
    }, 'thread');

    expect($order)->toBe(['store', 'steer']);
});
