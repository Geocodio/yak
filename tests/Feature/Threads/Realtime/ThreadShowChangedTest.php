<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\RunnerState;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Events\ThreadEventBroadcast;
use App\Exceptions\ThreadActionRefused;
use App\Models\CoOwnerRequest;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\EarlyWarningState;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadShowChanged;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__ . '/RealtimeRedis.php';
require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    config(['yak.threads.realtime_key_prefix' => parallelToken() . ':']);
    useThrowawayRedis();
    Event::fake([ThreadEventBroadcast::class]);
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    $this->user = User::factory()->create();
});

afterEach(fn () => stopThrowawayRedis());

/**
 * The thread events published since the callback started, as their data.
 *
 * @return list<array<string, mixed>>
 */
function threadEventsDuring(Thread $thread, Closure $change): array
{
    $before = Event::dispatched(ThreadEventBroadcast::class)->count();
    $change();

    return Event::dispatched(ThreadEventBroadcast::class)->slice($before)->map(fn (array $arguments): ThreadEventBroadcast => $arguments[0])
        ->filter(fn (ThreadEventBroadcast $event): bool => $event->type === 'thread' && $event->threadId === $thread->id)
        ->map(fn (ThreadEventBroadcast $event): array => $event->data)->values()->all();
}

function expectShowChanged(Thread $thread, Closure $change): void
{
    $keyBefore = ThreadShowChanged::key($thread->id);
    $events = threadEventsDuring($thread, $change);
    $keyAfter = ThreadShowChanged::key($thread->id);

    expect($keyAfter)->not->toBe($keyBefore)
        ->and($events)->not->toBe([])
        ->and(end($events)['showKey'])->toBe($keyAfter);
}

function showChangedTask(array $attributes = []): YakTask
{
    return YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'web', 'pr_url' => null, ...$attributes]);
}

it('publishes the thread state when a co-owner request is decided', function () {
    $task = showChangedTask(['status' => TaskStatus::AwaitingCoOwner]);
    $thread = Thread::findOrFail($task->thread_id);
    $request = CoOwnerRequest::factory()->create(['task_id' => $task->id]);

    expectShowChanged($thread, fn () => $request->update(['status' => CoOwnerRequestStatus::Approved]));
});

it('publishes the thread state when a co-owner request is created', function () {
    $task = showChangedTask(['status' => TaskStatus::AwaitingCoOwner]);
    $thread = Thread::findOrFail($task->thread_id);

    expectShowChanged($thread, fn () => CoOwnerRequest::factory()->create(['task_id' => $task->id]));
});

it('publishes the thread state when the early warning is answered', function () {
    $task = showChangedTask(['early_warning' => ['areas' => [['name' => 'Billing', 'risk' => 'high']], 'state' => 'shown']]);
    $thread = Thread::findOrFail($task->thread_id);

    expectShowChanged($thread, fn () => app(EarlyWarningState::class)->declined($task));
});

it('publishes the thread state when a repository is answered', function () {
    $task = YakTask::factory()->create(['repo' => 'unknown', 'status' => TaskStatus::AwaitingClarification, 'clarification_options' => ['web'], 'started_by_user_id' => $this->user->id]);
    $thread = app(ThreadFactory::class)->forRequestWithoutRepository($task);

    expectShowChanged($thread, fn () => $this->actingAs($this->user)->post(route('threads.repository-answer.store', $thread), ['repo' => 'web'])->assertRedirect());
});

it('publishes the thread state on a retry, a cancel and a stop', function () {
    $failed = showChangedTask(['status' => TaskStatus::Failed, 'stop_kind' => StopKind::YakStopped, 'stop_summary' => 'Stopped.']);
    $thread = Thread::findOrFail($failed->thread_id);

    expectShowChanged($thread, fn () => $this->actingAs($this->user)->post(route('threads.retry', $thread))->assertRedirect());

    $running = showChangedTask(['status' => TaskStatus::Running]);
    $runningThread = Thread::findOrFail($running->thread_id);

    expectShowChanged($runningThread, fn () => $this->actingAs($this->user)->post(route('threads.cancel', $runningThread))->assertRedirect());

    $stopping = showChangedTask(['status' => TaskStatus::Running]);
    $stoppingThread = Thread::findOrFail($stopping->thread_id);

    expectShowChanged($stoppingThread, fn () => $stopping->update(['status' => TaskStatus::Failed, 'stop_kind' => StopKind::NeedsYou, 'stop_summary' => 'Needs a decision.']));
});

it('keeps the key the same for an unrelated change', function () {
    $task = showChangedTask(['status' => TaskStatus::Running]);
    $thread = Thread::findOrFail($task->thread_id);
    $before = ThreadShowChanged::key($thread->id);

    $task->update(['num_turns' => 3]);

    expect(ThreadShowChanged::key($thread->id))->toBe($before);
});

it('turns a refused thread action into a redirect back with the error, only on thread routes', function () {
    Route::middleware('web')->post('/thread-refusal-test/threads', fn () => throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'Closed.'))->name('threads.refusal-test');
    Route::middleware('web')->post('/thread-refusal-test/other', fn () => throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'Closed.'))->name('other.refusal-test');

    $this->actingAs($this->user)->from('/somewhere')->post('/thread-refusal-test/threads')
        ->assertRedirect('/somewhere')
        ->assertSessionHas('error', 'Closed.');

    $this->actingAs($this->user)->from('/somewhere')->post('/thread-refusal-test/other')
        ->assertStatus(500)
        ->assertSessionMissing('error');
});

it('publishes the thread state when a queued message is queued, edited, switched and withdrawn', function () {
    [$thread, $run] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $author = User::factory()->create();
    $thread->forceFill(['model' => 'sonnet', 'owner_user_id' => $author->id, 'created_by_user_id' => $author->id])->save();
    $message = null;

    expectShowChanged($thread, function () use ($thread, $author, &$message) {
        $message = app(ThreadTurns::class)->steer($thread, 'Then update the docs', $author, null, source: 'thread', isQueueOnly: true);
    });
    expectShowChanged($thread, fn () => $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['text' => 'Then the README'])->assertRedirect());
    $thread->forceFill(['runner_state' => RunnerState::Starting])->save();
    expectShowChanged($thread, fn () => $this->actingAs($author)->patch(route('threads.queued-messages.update', [$thread, $message->id]), ['mode' => 'steer'])->assertRedirect());
    expectShowChanged($thread, fn () => $this->actingAs($author)->delete(route('threads.queued-messages.destroy', [$thread, $message->id]))->assertRedirect());
});

it('keeps the key the same for a message queued from a channel, which the page does not list', function () {
    [$thread] = runningTurnFor();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $before = ThreadShowChanged::key($thread->id);

    PendingSteeringMessage::query()->create(['root_task_id' => $task->id, 'text' => 'from slack', 'source' => 'slack', 'mode' => 'queue', 'uuid' => (string) Str::uuid()]);

    expect(ThreadShowChanged::key($thread->id))->toBe($before);
});
