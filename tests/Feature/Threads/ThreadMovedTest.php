<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadSource;
use App\Enums\ThreadStatus;
use App\Http\Resources\ThreadOriginData;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

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
