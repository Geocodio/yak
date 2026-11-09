<?php

use App\DataTransferObjects\StreamEntry;
use App\Enums\SteeringMode;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Jobs\FlushSteeringMessagesJob;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\Threads\SteeringDelivery;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadMessages;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    Event::fake([TurnStarted::class, TurnCompleted::class, TurnFailed::class]);
    Queue::fake();

    $this->app->instance(ThreadStreams::class, new FakeThreadStreams);
    $this->thread = Thread::factory()->paused()->create([
        'lock_holder' => ThreadLockHolder::Sandbox,
        'claude_account_id' => ClaudeAccount::factory()->create()->id,
    ]);
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
    $this->run = TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'turn_state' => TurnState::Running,
        'prompt' => 'Fix the bug',
        'prompt_uuid' => 'prompt-1',
        'command_states' => ['prompt-1' => 'started'],
        'prompt_read_after_sequence' => 0,
        'session_id' => 'session-1',
        'cost_usd' => 0,
    ]);
    $this->thread->forceFill(['lock_turn_id' => $this->run->id])->save();
});

function sendMessageSteer(): PendingSteeringMessage
{
    $message = PendingSteeringMessage::queueFor(test()->task, 'also handle nulls', 'dashboard', mode: SteeringMode::Steer, authorName: 'Ada', model: 'opus');
    app(SteeringDelivery::class)->deliver(test()->task);

    return $message->refresh();
}

function messageLine(array $line): void
{
    static $counter = 5000;
    $counter++;
    app(ThreadDriver::class)->handle(new StreamEntry(test()->thread->channel(), $counter . '-0', 1, $counter, $line));
}

function steerLifecycle(string $uuid, string $state): void
{
    messageLine(['type' => 'command_lifecycle', 'command_uuid' => $uuid, 'state' => $state, 'session_id' => 'session-1']);
}

function steerEntry(Collection $messages, string $id): array
{
    return $messages->firstWhere('id', $id);
}

it('shows a steer as sent until the model takes it in', function () {
    $message = sendMessageSteer();
    steerLifecycle($message->uuid, 'queued');

    $entry = steerEntry(ThreadMessages::for($this->thread), 'steer-' . $message->id);

    expect($entry['state'])->toBe('sent')
        ->and($entry['readAfterSequence'])->toBeNull()
        ->and($entry['text'])->toBe('also handle nulls')
        ->and($entry['authorName'])->toBe('Ada')
        ->and($entry['model'])->toBe('opus');
});

it('marks a steer read with where it was read', function () {
    $message = sendMessageSteer();
    $publisher = app(ThreadEventPublisher::class);

    foreach (range(1, 5) as $index) {
        $publisher->append($this->thread->id, 'entry', ['n' => $index]);
    }

    steerLifecycle($message->uuid, 'started');

    $entry = steerEntry(ThreadMessages::for($this->thread->fresh()), 'steer-' . $message->id);

    expect($entry['state'])->toBe('read')
        ->and($entry['readAfterSequence'])->toBeGreaterThanOrEqual(5)
        ->and($entry['readAt'])->not->toBeNull();
});

it('places a message taken in after the final answer after that answer', function () {
    $message = sendMessageSteer();

    messageLine(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Done', 'session_id' => 'session-1', 'total_cost_usd' => 0.1]);
    $resultSequence = (int) ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'entry')->max('sequence');

    steerLifecycle($message->uuid, 'started');

    $entry = steerEntry(ThreadMessages::for($this->thread->fresh()), 'steer-' . $message->id);

    expect($resultSequence)->toBeGreaterThan(0)
        ->and($entry['readAfterSequence'])->toBeGreaterThanOrEqual($resultSequence);
});

it('lists a follow-up waiting on a usage limit as sent', function () {
    $this->thread->forceFill(['blocked_reason' => ThreadBlockReason::UsageLimit])->save();
    $followUp = YakTask::factory()->create(['thread_id' => $this->thread->id, 'parent_task_id' => $this->task->id, 'description' => 'One more thing', 'author_name' => 'Grace']);

    $entry = steerEntry(ThreadMessages::for($this->thread), 'task-' . $followUp->id);

    expect($entry['state'])->toBe('sent')
        ->and($entry['text'])->toBe('One more thing')
        ->and($entry['authorName'])->toBe('Grace')
        ->and(steerEntry(ThreadMessages::for($this->thread), 'run-' . $this->run->id)['state'])->toBe('read');
});

it('publishes a message.state event when a message is read', function () {
    $message = sendMessageSteer();

    steerLifecycle($message->uuid, 'started');

    $event = ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'message.state')->first();

    expect($event->payload)->toMatchArray(['id' => 'steer-' . $message->id, 'uuid' => $message->uuid, 'state' => 'read'])
        ->and($event->payload['readAfterSequence'])->toBeInt();
});

it('marks a cancelled prompt cancelled', function () {
    $this->run->forceFill(['prompt_uuid' => 'prompt-2', 'command_states' => ['prompt-2' => 'queued']])->save();

    steerLifecycle('prompt-2', 'cancelled');

    expect(steerEntry(ThreadMessages::for($this->thread), 'run-' . $this->run->id)['state'])->toBe('cancelled');
});

it('appends a sent event when a person steers a thread', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('tasks.messages.store', $this->task), ['message' => 'look at the cache', 'mode' => 'queue'])
        ->assertRedirect();

    $event = ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'message.state')->first();

    expect($event->payload)->toMatchArray(['state' => 'sent', 'readAfterSequence' => null])
        ->and($event->payload['id'])->toStartWith('steer-');
});

it('shows the typed text of a turn, not the assembled prompt', function () {
    $task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id, 'parent_task_id' => $this->task->id, 'description' => 'Rename the helper']);
    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $this->thread->id,
        'prompt' => "You are Yak. Repository instructions...\n\nRename the helper",
    ]);

    expect(steerEntry(ThreadMessages::for($this->thread), 'run-' . $run->id)['text'])->toBe('Rename the helper');
});

it('lists a follow-up that failed before its turn started as cancelled', function () {
    $followUp = YakTask::factory()->create(['thread_id' => $this->thread->id, 'parent_task_id' => $this->task->id, 'status' => TaskStatus::Failed]);

    expect(steerEntry(ThreadMessages::for($this->thread), 'task-' . $followUp->id)['state'])->toBe('cancelled');
});

it('tells clients which task a flushed steer merged into', function () {
    $this->task->update(['status' => TaskStatus::Success, 'mode' => TaskMode::Research]);
    $steer = PendingSteeringMessage::queueFor($this->task, 'also handle nulls', 'dashboard');

    (new FlushSteeringMessagesJob($this->task->id))->handle(app(FollowUpTaskFactory::class));

    $events = ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'message.state')->get();
    $merged = $events->first(fn ($event) => $event->payload['id'] === 'steer-' . $steer->id);

    expect($merged->payload)->toMatchArray(['state' => 'merged'])
        ->and($merged->payload['into'])->toStartWith('task-');
});
