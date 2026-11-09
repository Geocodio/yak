<?php

use App\DataTransferObjects\StreamEntry;
use App\Enums\SteeringMode;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Http\Controllers\Tasks\QueuedMessageController;
use App\Jobs\FlushSteeringMessagesJob;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\ThreadBuilder;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    Event::fake([TurnStarted::class, TurnCompleted::class, TurnFailed::class]);
    Queue::fake();

    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    $this->thread = Thread::factory()->paused()->create([
        'lock_holder' => ThreadLockHolder::Sandbox,
        'claude_account_id' => ClaudeAccount::factory()->create()->id,
    ]);
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
    $this->run = TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'turn_state' => TurnState::Running,
        'prompt_uuid' => 'prompt-1',
        'command_states' => ['prompt-1' => 'started'],
        'session_id' => 'session-1',
        'cost_usd' => 0,
    ]);
    $this->thread->forceFill(['lock_turn_id' => $this->run->id])->save();
});

function queueSteer(string $text = 'also handle nulls'): PendingSteeringMessage
{
    return PendingSteeringMessage::queueFor(test()->task, $text, 'dashboard', mode: SteeringMode::Steer, authorName: 'Ada');
}

function steerLine(string $uuid, string $state): void
{
    static $counter = 1000;
    $counter++;
    app(ThreadDriver::class)->handle(new StreamEntry(
        test()->thread->channel(),
        $counter . '-0',
        1,
        $counter,
        ['type' => 'command_lifecycle', 'command_uuid' => $uuid, 'state' => $state, 'session_id' => 'session-1'],
    ));
}

it('sends a steer with the wrapper text and a uuid', function () {
    $message = queueSteer();

    expect(app(SteeringDelivery::class)->deliver($this->task))->toBe(1);

    $command = $this->streams->commandsFor($this->thread->channel())[0];
    $message->refresh();

    expect($command['type'])->toBe('steer')
        ->and($command['uuid'])->toBe($message->uuid)
        ->and($command['text'])->toBe("The person you are working for sent this message while you were working. Take it into account from here on:\n\nalso handle nulls")
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->task_run_id)->toBe($this->run->id)
        ->and($this->run->fresh()->command_states[$message->uuid]->value)->toBe('sent');

    expect(app(SteeringDelivery::class)->deliver($this->task))->toBe(0);
});

it('marks delivered on queued and absorbs on started', function () {
    $message = queueSteer();
    app(SteeringDelivery::class)->deliver($this->task);
    $uuid = $message->fresh()->uuid;

    steerLine($uuid, 'queued');
    expect($message->fresh()->delivered_at)->not->toBeNull();

    steerLine($uuid, 'started');

    expect(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeFalse()
        ->and(PendingSteeringMessage::query()->withoutGlobalScopes()->find($message->id)->absorbed_at)->not->toBeNull()
        ->and(TaskLog::query()->where('yak_task_id', $this->task->id)->where('message', ThreadBuilder::STEERING_MESSAGE_LOG)->count())->toBe(1);
});

it('keeps an unsent steer withdrawable and a sent one not', function () {
    $unsent = queueSteer('unsent');
    $sent = queueSteer('sent');
    $sent->forceFill(['uuid' => 'steer-1', 'sent_at' => now()])->save();

    $controller = app(QueuedMessageController::class);

    $controller->destroy($this->task, $sent->id);
    expect(PendingSteeringMessage::query()->whereKey($sent->id)->exists())->toBeTrue();

    $controller->destroy($this->task, $unsent->id);
    expect(PendingSteeringMessage::query()->whereKey($unsent->id)->exists())->toBeFalse();
});

it('starts a new turn for a steer that arrives after the turn ended', function () {
    $this->run->forceFill(['turn_state' => TurnState::Completed])->save();
    $message = queueSteer();

    expect(app(SteeringDelivery::class)->deliver($this->task))->toBe(0);

    expect($message->fresh()->sent_at)->toBeNull()
        ->and($this->streams->commandsFor($this->thread->channel()))->toBe([]);
    Queue::assertPushed(FlushSteeringMessagesJob::class, fn ($job) => $job->rootTaskId === $this->task->id);
});

it('never drops a steer when the runner is gone', function () {
    $message = queueSteer();
    app(SteeringDelivery::class)->deliver($this->task);
    $uuid = $message->fresh()->uuid;
    $before = count($this->streams->commandsFor($this->thread->channel()));

    app(ThreadDriver::class)->handle(new StreamEntry($this->thread->channel(), '2000-0', 1, 2000, ['type' => 'broker', 'event' => 'no_runner', 'uuid' => $uuid], true));

    $steers = array_values(array_filter(array_slice($this->streams->commandsFor($this->thread->channel()), $before), fn (array $command) => $command['type'] === 'steer'));
    expect($steers)->toHaveCount(1)
        ->and($steers[0]['uuid'])->toBe($uuid)
        ->and($message->fresh()->delivered_at)->toBeNull();
});

it('reports the steering state of a message', function () {
    $message = queueSteer();
    expect($message->steeringState())->toBe('waiting');

    $message->forceFill(['sent_at' => now()])->save();
    expect($message->steeringState())->toBe('sent');

    $message->forceFill(['delivered_at' => now()])->save();
    expect($message->steeringState())->toBe('delivered');

    $message->forceFill(['delivered_at' => now()->subSeconds(10)])->save();
    expect($message->steeringState())->toBe('queued_until_step_finishes');
});

it('switches the model before a steer without restarting', function () {
    $this->thread->forceFill(['model' => 'sonnet', 'runner_model' => 'sonnet'])->save();
    $message = queueSteer();
    $message->forceFill(['model' => 'haiku'])->save();

    app(SteeringDelivery::class)->deliver($this->task);

    $commands = $this->streams->commandsFor($this->thread->channel());

    expect(array_column($commands, 'type'))->toBe(['set_model', 'steer'])
        ->and($commands[0]['model'])->toBe('haiku')
        ->and($message->fresh()->model)->toBe('haiku')
        ->and($this->thread->fresh()->model)->toBe('haiku')
        ->and($this->thread->fresh()->runner_model)->toBe('haiku');
});
