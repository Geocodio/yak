<?php

use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Enums\SteeringMode;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Devices\ShortLockWait;
use App\Services\Threads\QueuedMessageRunner;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadMessageSender;
use App\Services\Threads\ThreadTurns;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('keeps one queued row and replays one result when the runner command throws', function () {
    [$thread, $run] = runningThreadForSteering();
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once()->andThrow(new RuntimeException('runner unreachable'));
    $input = new ThreadMessageInput('Use the staging bucket', null, null, SEND_KEY, MessageDelivery::Steer, null);
    $user = User::factory()->create();

    $first = app(ThreadMessageSender::class)->send($thread, $user, $input);
    $second = app(ThreadMessageSender::class)->send($thread, $user, $input);

    expect($first->status)->toBe('queued')
        ->and($second)->toEqual($first)
        ->and(PendingSteeringMessage::query()->where('text', 'Use the staging bucket')->count())->toBe(1)
        ->and(PendingSteeringMessage::query()->first()->mode)->toBe(SteeringMode::Queue)
        ->and(ThreadTurnMessage::query()->where('send_key', SEND_KEY)->exists())->toBeFalse();
});

it('sends a message that fell back to the queue after the turn completed', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $run] = runningThreadForSteering();
    $run->forceFill(['turn_state' => TurnState::Completed, 'finished_at' => now()])->save();
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();

    $message = app(ThreadTurns::class)->steer($thread, 'Then update the docs', User::factory()->create(), null, null, 'thread');

    $started = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();
    expect($started->id)->not->toBe($run->id)
        ->and($started->prompt)->toContain('Then update the docs')
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});

it('starts a turn for a message queued on purpose after the turn completed', function () {
    fakeTurnCollaborators()['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $run] = runningThreadForSteering();
    $run->forceFill(['turn_state' => TurnState::Completed, 'finished_at' => now()])->save();
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();

    $message = app(ThreadTurns::class)->steer($thread, 'Then update the docs', User::factory()->create(), null, null, 'thread', null, true);

    expect(TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first()->prompt)->toContain('Then update the docs')
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});

it('keeps a message queued on purpose waiting while the turn still runs', function () {
    fakeTurnCollaborators();
    [$thread] = runningThreadForSteering();

    $message = app(ThreadTurns::class)->steer($thread, 'Then update the docs', User::factory()->create(), null, null, 'thread', null, true);

    expect(TaskRun::query()->where('thread_id', $thread->id)->count())->toBe(1)
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('never starts a turn from a steer that is not a person\'s thread message', function (string $source, bool $hasSender, bool $isQueueOnly) {
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->andThrow(new RuntimeException('runner gone'));
    [$thread, $run] = runningThreadForSteering();
    $run->forceFill(['turn_state' => TurnState::Completed, 'finished_at' => now()])->save();
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();

    $message = app(ThreadTurns::class)->steer($thread, 'Framed note', $hasSender ? User::factory()->create() : null, null, null, $source, null, $isQueueOnly);

    expect(TaskRun::query()->where('thread_id', $thread->id)->count())->toBe(1)
        ->and($message->mode)->toBe(SteeringMode::Queue)
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
})->with([
    'system without a sender' => ['system', false, false],
    'system with a sender' => ['system', true, false],
    'thread without a sender' => ['thread', false, false],
    'thread without a sender queued on purpose' => ['thread', false, true],
]);

it('does not answer a second person with the first person\'s message for the same send key', function () {
    [$thread] = runningThreadForSteering();
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->twice();
    $input = new ThreadMessageInput('Use the staging bucket', null, null, SEND_KEY, MessageDelivery::Steer, null);
    [$first, $second] = User::factory()->count(2)->create();

    $firstResult = app(ThreadMessageSender::class)->send($thread, $first, $input);
    Cache::flush();
    $secondResult = app(ThreadMessageSender::class)->send($thread, $second, $input);

    expect($secondResult->uuid)->not->toBe($firstResult->uuid)
        ->and(PendingSteeringMessage::query()->where('send_key', SEND_KEY)->count())->toBe(2);
});

it('answers 503 when start or stop meets a thread lock that does not free up', function (string $method) {
    $fakes = fakeTurnCollaborators();
    [$thread, $task] = threadWithTask();
    $timeout = new QueryException('sqlite', 'select 1', [], new PDOException('lock wait timeout'));
    $timeout->errorInfo = [null, 1205, 'Lock wait timeout exceeded'];
    Thread::retrieved(fn () => throw $timeout);

    $call = $method === 'start'
        ? fn () => app(ThreadTurns::class)->start($task, 'Do it', User::factory()->create(), null)
        : fn () => app(ThreadTurns::class)->stop($thread);

    expect($call)->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503));
})->with(['start', 'stop']);

it('does not steer a message that a new prompt already drained', function () {
    [$thread] = runningThreadForSteering();
    $commands = fakeTurnCollaborators()['commands'];
    $commands->shouldNotReceive('steer');
    $message = PendingSteeringMessage::query()->create(['root_task_id' => $thread->tasks()->oldest()->first()->rootTask()->id, 'text' => 'Use the staging bucket', 'source' => 'thread', 'mode' => SteeringMode::Steer, 'uuid' => (string) Str::uuid()]);
    $stale = PendingSteeringMessage::query()->findOrFail($message->id);
    $message->delete();

    expect(app(SteeringDelivery::class)->deliver($thread, $stale))->toBeTrue()
        ->and(ThreadTurnMessage::query()->where('uuid', $stale->uuid)->exists())->toBeFalse();
});

it('answers sent when the fallback turn drained the message', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $run] = runningThreadForSteering();
    $run->forceFill(['turn_state' => TurnState::Completed, 'finished_at' => now()])->save();
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();
    $result = app(ThreadTurns::class)->steer($thread, 'Then update the docs', User::factory()->create(), null, null, 'thread', SEND_KEY);

    expect($result->mode)->toBe(SteeringMode::Steer);
});

it('replays a message that is already recorded for the send key without acting again', function () {
    [$thread] = runningThreadForSteering();
    fakeTurnCollaborators()['commands']->shouldReceive('steer')->once();
    $input = new ThreadMessageInput('Use the staging bucket', null, null, SEND_KEY, MessageDelivery::Steer, null);
    $user = User::factory()->create();

    $first = app(ThreadMessageSender::class)->send($thread, $user, $input);
    Cache::flush();
    $second = app(ThreadMessageSender::class)->send($thread, $user, $input);

    expect($second)->toEqual($first)
        ->and(PendingSteeringMessage::query()->where('send_key', SEND_KEY)->count())->toBe(1);
});

it('answers 503 when a thread lock wait runs out', function () {
    $timeout = new QueryException('sqlite', 'select 1', [], new PDOException('lock wait timeout'));
    $timeout->errorInfo = [null, 1205, 'Lock wait timeout exceeded'];

    expect(fn () => ShortLockWait::threadTransaction(fn () => throw $timeout))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503)->and($exception->getMessage())->toContain('Try again'));
});

it('queues a message when the thread lock wait runs out', function () {
    [$thread] = runningThreadForSteering();
    fakeTurnCollaborators()['commands']->shouldNotReceive('steer');
    $timeout = new QueryException('sqlite', 'select 1', [], new PDOException('lock wait timeout'));
    $timeout->errorInfo = [null, 1205, 'Lock wait timeout exceeded'];
    $this->mock(SteeringDelivery::class)->shouldReceive('deliver')->andReturnUsing(fn () => ShortLockWait::threadTransaction(fn () => throw $timeout));

    $message = app(ThreadTurns::class)->steer($thread, 'Use the staging bucket', null, null, null, 'thread');

    expect($message->mode)->toBe(SteeringMode::Queue)
        ->and(PendingSteeringMessage::query()->count())->toBe(1);
});

it('does not send a drained message again when its key is repeated after the cache is gone', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $run] = runningThreadForSteering();
    $user = User::factory()->create();
    $other = User::factory()->create();
    $keys = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
    $first = new ThreadMessageInput('Then update the docs', null, null, $keys[0], MessageDelivery::AfterTurn, null);
    $second = new ThreadMessageInput('And run the linter', null, null, $keys[1], MessageDelivery::AfterTurn, null);
    $answers = [
        app(ThreadMessageSender::class)->send($thread, $user, $first),
        app(ThreadMessageSender::class)->send($thread, $user, $second),
    ];
    $run->forceFill(['turn_state' => TurnState::Completed, 'finished_at' => now()])->save();
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();

    $started = app(QueuedMessageRunner::class)->afterTurn($run->refresh());
    $turnCount = TaskRun::query()->where('thread_id', $thread->id)->count();
    $rowCount = ThreadTurnMessage::query()->count();
    expect($started?->prompt)->toContain('Then update the docs')->toContain('And run the linter')
        ->and(PendingSteeringMessage::query()->count())->toBe(0);
    $started->forceFill(['turn_state' => TurnState::Completed])->save();
    $thread->refresh()->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();
    Cache::flush();

    foreach ([[$first, 0], [$second, 1]] as [$input, $index]) {
        $replayed = app(ThreadMessageSender::class)->send($thread, $user, $input);
        expect($replayed->uuid)->toBe($answers[$index]->uuid);
    }

    expect(TaskRun::query()->where('thread_id', $thread->id)->count())->toBe($turnCount)
        ->and(ThreadTurnMessage::query()->count())->toBe($rowCount)
        ->and(PendingSteeringMessage::query()->count())->toBe(0);

    $fakes['commands']->shouldReceive('steer');
    $result = app(ThreadMessageSender::class)->send($thread, $other, $first);
    expect($result->uuid)->not->toBe($answers[0]->uuid);
});

it('does not answer a second person with the first person\'s started turn for the same send key', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel', 'steer');
    [$thread] = threadWithTask();
    $input = new ThreadMessageInput('Do it', null, null, SEND_KEY, MessageDelivery::Steer, null);
    [$first, $second] = User::factory()->count(2)->create();

    $firstResult = app(ThreadMessageSender::class)->send($thread, $first, $input);
    Cache::flush();
    $secondResult = app(ThreadMessageSender::class)->send($thread, $second, $input);

    expect($secondResult->uuid)->not->toBe($firstResult->uuid)
        ->and(PendingSteeringMessage::query()->where('send_key', SEND_KEY)->where('author_user_id', $second->id)->exists())->toBeTrue();
});
