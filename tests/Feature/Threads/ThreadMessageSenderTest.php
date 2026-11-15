<?php

use App\DataTransferObjects\ThreadMessageInput;
use App\DataTransferObjects\ThreadMessageResult;
use App\Enums\MessageDelivery;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Exceptions\ThreadActionRefused;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Threads\SendKeys;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadMessageSender;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

const SENDER_KEY = '0b9c3e0e-6d5c-4d7e-9b7a-2f5d1c6a9e11';

function senderInput(string $text = 'Use the staging bucket', MessageDelivery $delivery = MessageDelivery::Steer, ?string $key = SENDER_KEY, ?string $intent = null): ThreadMessageInput
{
    return new ThreadMessageInput($text, null, $intent, $key, $delivery, null);
}

/** @return array{0: Thread, 1: User} */
function runningThreadForSender(): array
{
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet'])->save();

    return [$thread, User::factory()->create(['name' => 'Test person'])];
}

it('returns the first result for a repeated send key and sends once', function () {
    [$thread, $user] = runningThreadForSender();
    $input = senderInput(delivery: MessageDelivery::AfterTurn);

    $first = app(ThreadMessageSender::class)->send($thread, $user, $input);
    $second = app(ThreadMessageSender::class)->send($thread, $user, $input);

    expect($first->status)->toBe('queued')
        ->and($first->uuid)->not->toBeNull()
        ->and($second)->toEqual($first)
        ->and(PendingSteeringMessage::where('text', 'Use the staging bucket')->count())->toBe(1);
});

it('starts a turn on an idle thread for both deliveries', function (MessageDelivery $delivery) {
    fakeTurnCollaborators()['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $task] = threadWithTask();
    $user = User::factory()->create();

    $result = app(ThreadMessageSender::class)->send($thread, $user, senderInput(delivery: $delivery, key: null));

    expect($result->status)->toBe('sent')
        ->and($result->uuid)->toBe(TaskRun::where('thread_id', $thread->id)->latest('id')->value('prompt_uuid'));
})->with([MessageDelivery::Steer, MessageDelivery::AfterTurn]);

it('steers a running turn and queues when delivery refuses', function () {
    [$thread, $user] = runningThreadForSender();
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once();

    $sent = app(ThreadMessageSender::class)->send($thread, $user, senderInput('Steer this', key: null));
    expect($sent->status)->toBe('sent');

    $this->mock(SteeringDelivery::class)->shouldReceive('deliver')->andReturn(false);
    $queued = app(ThreadMessageSender::class)->send($thread, $user, senderInput('Queue this', key: null));
    expect($queued->status)->toBe('queued');
});

it('answers a repeat that arrives while the first send runs', function () {
    [$thread, $user] = runningThreadForSender();
    app()->instance(SendKeys::class, new SendKeys(lockWaitSeconds: 0));
    $name = "thread-send-result:{$thread->id}:{$user->id}:" . SENDER_KEY;
    $lock = Cache::lock("{$name}:lock", 30);
    expect($lock->get())->toBeTrue();

    try {
        app(ThreadMessageSender::class)->send($thread, $user, senderInput(delivery: MessageDelivery::AfterTurn));
        $this->fail('Expected a 503.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503)
            ->and(Cache::get($name))->toBeNull()
            ->and(PendingSteeringMessage::count())->toBe(0);
    }

    $lock->release();
    app(ThreadMessageSender::class)->send($thread, $user, senderInput(delivery: MessageDelivery::AfterTurn));

    expect(PendingSteeringMessage::count())->toBe(1);
});

it('runs again after a refused first attempt', function () {
    [$thread, $user] = runningThreadForSender();
    $thread->forceFill(['status' => ThreadStatus::Closed])->save();

    expect(fn () => app(ThreadMessageSender::class)->send($thread, $user, senderInput(delivery: MessageDelivery::AfterTurn)))
        ->toThrow(fn (ThreadActionRefused $refusal) => $refusal->errorCode === 'thread_closed');

    $thread->forceFill(['status' => ThreadStatus::Open])->save();
    $result = app(ThreadMessageSender::class)->send($thread->refresh(), $user, senderInput(delivery: MessageDelivery::AfterTurn));

    expect($result->status)->toBe('queued')
        ->and(PendingSteeringMessage::count())->toBe(1);
});

it('stores the clear result with the new thread id', function () {
    [$thread] = threadWithTask();
    $thread->forceFill(['blocked_reason' => null])->save();
    $user = User::factory()->create();
    $input = senderInput('/clear');

    $first = app(ThreadMessageSender::class)->send($thread, $user, $input);
    $second = app(ThreadMessageSender::class)->send($thread, $user, $input);

    expect(Thread::whereNotNull('continued_from_thread_id')->count())->toBe(1)
        ->and($first->threadId)->toBe(Thread::whereNotNull('continued_from_thread_id')->value('public_id'))
        ->and($second)->toEqual($first)
        ->and($first->uuid)->toBeNull()
        ->and($first->toArray())->toHaveKey('uuid', null);
});

it('ignores a value the old web code left under the key', function () {
    [$thread, $user] = runningThreadForSender();
    Cache::put("thread-send:{$thread->id}:{$user->id}:" . SENDER_KEY, true, 600);
    Cache::put("thread-send-result:{$thread->id}:{$user->id}:" . SENDER_KEY, true, 600);
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once();

    $result = app(ThreadMessageSender::class)->send($thread, $user, senderInput());

    expect($result)->toBeInstanceOf(ThreadMessageResult::class)
        ->and($result->status)->toBe('sent')
        ->and(PendingSteeringMessage::where('text', 'Use the staging bucket')->count())->toBe(1);
});

it('refuses each state with its code', function (Closure $arrange, string $code, string $message, array $details) {
    [$thread, $user] = runningThreadForSender();
    $input = $arrange($thread);
    $input = $input instanceof ThreadMessageInput ? $input : null;

    try {
        app(ThreadMessageSender::class)->send($thread->refresh(), $user, $input ?? senderInput(key: null));
        $this->fail('Expected a refusal.');
    } catch (ThreadActionRefused $refusal) {
        expect($refusal->errorCode)->toBe($code)
            ->and($refusal->getMessage())->toBe($message)
            ->and($refusal->details)->toBe($details);
    }
})->with([
    'closed' => [fn (Thread $thread) => $thread->forceFill(['status' => ThreadStatus::Closed])->save(), 'thread_closed', 'This thread is closed.', []],
    'device' => [fn (Thread $thread) => $thread->forceFill(['owner_side' => ThreadOwnerSide::Device])->save(), 'thread_owned_by_device', 'Your computer is working on this thread. Hand it back to Yak before sending a message.', []],
    'waiting' => [fn (Thread $thread) => $thread->forceFill(['blocked_reason' => ThreadBlockReason::WaitingForRepository])->save(), 'thread_blocked', 'This thread has not started yet. It starts when its repository is set up.', ['reason' => 'waiting_for_repository']],
    'no tasks' => [fn (Thread $thread) => $thread->tasks()->delete(), 'thread_blocked', 'This thread has not started yet. It starts when its repository is set up.', ['reason' => 'no_task']],
    'intent while running' => [fn () => senderInput(key: null, intent: 'fix_preview'), 'turn_running', 'Claude is already working on this thread.', []],
    'busy' => [function () {
        test()->mock(ThreadTurns::class)->shouldReceive('steer')->andThrow(new ThreadBusy);
    }, 'turn_running', 'This thread is already starting a turn. Give it a moment.', []],
    'taken by device' => [function () {
        test()->mock(ThreadTurns::class)->shouldReceive('steer')->andThrow(new ThreadNotOwnedByYak);
    }, 'thread_owned_by_device', 'Your computer is working on this thread. Hand it back to Yak before sending a message.', []],
]);
