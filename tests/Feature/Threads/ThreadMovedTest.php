<?php

use App\DataTransferObjects\ResolvedAccount;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadSource;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Enums\TurnState;
use App\Http\Resources\ThreadNoticeData;
use App\Http\Resources\ThreadOriginData;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadCloser;
use App\Services\Threads\ThreadFactory;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';
require_once __DIR__ . '/../../Support/AppApiHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);
    $this->user = User::factory()->create();
});

function slackTaskOn(string $repo): YakTask
{
    return YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => $repo, 'source' => 'slack', 'external_id' => 'C1-123', 'pr_url' => null, 'status' => TaskStatus::Failed]);
}

it('closes the thread and gives the task a new one in the other repository when it is rerouted', function () {
    $task = slackTaskOn('web');
    $old = Thread::query()->findOrFail($task->thread_id);
    $followUp = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'web', 'parent_task_id' => $task->id, 'thread_id' => $old->id, 'pr_url' => null]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $old->id]);

    $this->actingAs($this->user)->post(route('tasks.reroute', $task), ['repo' => 'api'])->assertRedirect();

    $new = Thread::query()->findOrFail($task->fresh()->thread_id);

    expect($new->id)->not->toBe($old->id)
        ->and($new->repo)->toBe('api')
        ->and($new->isOpen())->toBeTrue()
        ->and($new->continued_from_thread_id)->toBe($old->id)
        ->and($new->source)->toBe(ThreadSource::Slack)
        ->and($new->source_reference)->toBe($old->source_reference)
        ->and($followUp->fresh()->thread_id)->toBe($new->id)
        ->and($run->fresh()->thread_id)->toBe($old->id);

    $old->refresh();
    expect($old->status)->toBe(ThreadStatus::Closed)
        ->and($old->close_reason)->toBe('moved');
});

it('shows the moved notice and the source on the closed thread page', function () {
    $task = slackTaskOn('web');
    $old = Thread::query()->findOrFail($task->thread_id);

    $this->actingAs($this->user)->post(route('tasks.reroute', $task), ['repo' => 'api']);

    $new = Thread::query()->findOrFail($task->fresh()->thread_id);

    $this->actingAs($this->user)->get("/threads/{$old->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('notice.kind', 'moved')
        ->where('notice.repo', 'api')
        ->where('notice.threadId', $new->public_id)
        ->where('notice.title', 'Moved to api'));

    expect(ThreadOriginData::for($old->refresh())['source']['key'])->toBe('slack');
});

it('does not move a thread that has no repository yet, it only attaches the repository', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => Thread::UNKNOWN_REPOSITORY, 'source' => 'slack', 'status' => TaskStatus::AwaitingClarification]);
    $thread = Thread::query()->findOrFail($task->thread_id);

    $this->actingAs($this->user)->post(route('tasks.reroute', $task), ['repo' => 'api']);

    expect($task->fresh()->thread_id)->toBe($thread->id)
        ->and($thread->fresh()->repo)->toBe('api')
        ->and($thread->fresh()->isOpen())->toBeTrue()
        ->and(Thread::query()->count())->toBe(1);
});

it('moves into a thread without a repository when the repository becomes unknown', function () {
    $task = slackTaskOn('web');
    $old = Thread::query()->findOrFail($task->thread_id);

    $new = app(ThreadFactory::class)->moved($old, $task, Thread::UNKNOWN_REPOSITORY);

    expect($new->hasRepository())->toBeFalse()
        ->and($new->continued_from_thread_id)->toBe($old->id)
        ->and($new->repositories()->count())->toBe(0)
        ->and($task->fresh()->thread_id)->toBe($new->id)
        ->and($old->fresh()->isOpen())->toBeFalse();

    $this->actingAs($this->user)->get("/threads/{$old->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('notice.kind', 'moved')
        ->where('notice.repo', null)
        ->where('notice.title', 'Moved: waiting for a repository'));
});

it('gives a moved private thread the same owner and shared people', function () {
    $owner = User::factory()->create();
    $friend = User::factory()->create();
    $stranger = User::factory()->create();
    $task = slackTaskOn('web');
    $old = Thread::query()->findOrFail($task->thread_id);
    $old->forceFill(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $owner->id])->save();
    ThreadShare::query()->create(['thread_id' => $old->id, 'user_id' => $friend->id]);

    $new = app(ThreadFactory::class)->moved($old, $task, 'api');

    expect($new->visibility)->toBe(ThreadVisibility::Private)
        ->and($new->owner_user_id)->toBe($owner->id)
        ->and(ThreadShare::query()->where('thread_id', $new->id)->pluck('user_id')->all())->toBe([$friend->id]);

    $access = app(ThreadAccess::class);
    expect($access->canSee($owner, $new))->toBeTrue()
        ->and($access->canSee($friend, $new))->toBeTrue()
        ->and($access->canSee($stranger, $new))->toBeFalse();
});

it('shows a plain moved notice to a viewer who cannot see the new thread', function () {
    $viewer = User::factory()->create();
    $task = slackTaskOn('web');
    $old = Thread::query()->findOrFail($task->thread_id);
    $new = app(ThreadFactory::class)->moved($old, $task, 'api');
    $new->forceFill(['visibility' => ThreadVisibility::Private, 'owner_user_id' => User::factory()->create()->id])->save();

    $notice = ThreadNoticeData::for($old->refresh(), $viewer);

    expect($notice['kind'])->toBe('moved')
        ->and($notice['title'])->toBe('Moved')
        ->and($notice['repo'])->toBeNull()
        ->and($notice['threadId'])->toBeNull()
        ->and($notice['threadTitle'])->toBeNull();

    $this->getJson(appThreadPath($old), phoneHeadersFor($viewer))->assertOk()
        ->assertJsonPath('notice.kind', 'moved')
        ->assertJsonPath('notice.threadId', null)
        ->assertJsonPath('notice.repo', null);
});

it('keeps the moved notice when the old sandbox could not be closed', function () {
    $task = slackTaskOn('web');
    $old = Thread::query()->findOrFail($task->thread_id);
    $this->mock(ThreadCloser::class)->shouldReceive('close')->andThrow(new RuntimeException('busy'));
    Exceptions::fake();

    $new = app(ThreadFactory::class)->moved($old, $task, 'api');

    expect($old->fresh()->close_reason)->toBe('moved')
        ->and(ThreadNoticeData::for($old->fresh(), $this->user)['threadId'])->toBe($new->public_id);
    Exceptions::assertReported(RuntimeException::class);
});

it('does not show a moved notice on a thread that /clear continued', function () {
    $task = slackTaskOn('web');
    $old = Thread::query()->findOrFail($task->thread_id);
    app(ThreadFactory::class)->continuing($old, $task);

    expect(ThreadNoticeData::for($old->fresh(), $this->user))->toBeNull();
});

it('stops the running turn before it moves the task', function () {
    [$old, $run] = runningTurnFor();
    $old->update(['repo' => 'web']);
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'web', 'thread_id' => $old->id, 'pr_url' => null, 'status' => TaskStatus::Running]);
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('interrupt')->once();
    $fakes['sandbox']->shouldReceive('destroy')->once();
    $fakes['resolver']->shouldReceive('forNewThread')->andReturn(new ResolvedAccount(null, 'sonnet'));

    $this->actingAs($this->user)->post(route('tasks.reroute', $task), ['repo' => 'api'])->assertRedirect();

    expect($run->refresh()->turn_state)->toBe(TurnState::Interrupted)
        ->and($task->fresh()->thread_id)->not->toBe($old->id)
        ->and(ThreadTurns::activeTurn($old))->toBeNull()
        ->and(TaskRun::query()->where('yak_task_id', $task->id)->whereIn('turn_state', [TurnState::Running, TurnState::WaitingForAccount, TurnState::WaitingForSandbox])->count())->toBe(0);
});

it('refuses to move a task whose turn is still active after the stop', function () {
    [$old, $run] = runningTurnFor();
    $old->update(['repo' => 'web']);
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'web', 'thread_id' => $old->id, 'pr_url' => null, 'status' => TaskStatus::Running]);
    $this->mock(ThreadTurns::class)->shouldReceive('stop')->once();

    $this->actingAs($this->user)->post(route('tasks.reroute', $task), ['repo' => 'api']);

    expect($task->fresh()->repo)->toBe('web')
        ->and($task->fresh()->thread_id)->toBe($old->id)
        ->and($old->fresh()->isOpen())->toBeTrue()
        ->and(Thread::query()->count())->toBe(1);
});
