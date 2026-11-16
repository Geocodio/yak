<?php

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Http\Resources\ThreadMessagesData;
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

it('keeps a steer on the conversation after its pending row is drained', function () {
    [$thread, $run] = runningThreadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('steer')->once();

    $message = app(ThreadTurns::class)->steer($thread, 'also the footer', User::factory()->create(['name' => 'Ada']), null, null, 'thread');
    $message->delete();

    $shown = collect(ThreadMessagesData::for($thread))->firstWhere('uuid', $message->uuid);

    expect($shown['text'])->toBe('also the footer');
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

it('dates a delivered steer by when the person sent it', function () {
    [$thread] = runningThreadWithTask();
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once();
    $this->travelTo(now()->setTime(11, 44, 0));

    $message = app(ThreadTurns::class)->steer($thread, 'did you push?', User::factory()->create(), null, null, 'thread');
    ThreadTurnMessage::query()->where('uuid', $message->uuid)->update(['created_at' => now()->addMinutes(3)]);

    $shown = collect(ThreadMessagesData::for($thread))->firstWhere('uuid', $message->uuid);

    expect($shown['sentAt'])->toBe($message->created_at->toIso8601String());
});

it('does not send a steer the running turn already received in the next prompt', function () {
    [$thread, $run, $task] = runningThreadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('steer')->twice();

    $messages = [
        app(ThreadTurns::class)->steer($thread, 'Order test B', null, null, null, 'thread'),
        app(ThreadTurns::class)->steer($thread, 'Order test C', null, null, null, 'thread'),
    ];
    ThreadTurnMessage::query()->whereIn('uuid', collect($messages)->pluck('uuid'))->update(['state' => TurnMessageState::Started]);
    $run->forceFill(['turn_state' => TurnState::Completed])->save();
    $thread->forceFill(['runner_state' => RunnerState::Idle, 'lock_holder' => ThreadLockHolder::None])->save();

    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once()->with(Mockery::any(), Mockery::any(), 'QA test 6');

    app(ThreadTurns::class)->start($task, 'QA test 6', null, null);
});

it('sends a queued steer that was never delivered exactly once in the next prompt', function () {
    [$thread, $run, $task] = runningThreadWithTask();
    $fakes = fakeTurnCollaborators();
    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();
    $fakes['commands']->shouldNotReceive('steer');

    app(ThreadTurns::class)->steer($thread, 'Order test D', null, null, null, 'thread');
    $run->forceFill(['turn_state' => TurnState::Completed])->save();
    $thread->forceFill(['runner_state' => RunnerState::Idle, 'lock_holder' => ThreadLockHolder::None])->save();

    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once()->with(Mockery::any(), Mockery::any(), "- Order test D\n\nQA test 6");

    app(ThreadTurns::class)->start($task, 'QA test 6', null, null);
});

it('carries a steer the turn ended without reading into the next prompt once', function () {
    [$thread, $run, $task] = runningThreadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('steer')->once();

    app(ThreadTurns::class)->steer($thread, 'Order test E', null, null, null, 'thread');
    $run->forceFill(['turn_state' => TurnState::Completed])->save();
    $thread->forceFill(['runner_state' => RunnerState::Idle, 'lock_holder' => ThreadLockHolder::None])->save();

    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once()->with(Mockery::any(), Mockery::any(), "- Order test E\n\nQA test 6");

    app(ThreadTurns::class)->start($task, 'QA test 6', null, null);
});
