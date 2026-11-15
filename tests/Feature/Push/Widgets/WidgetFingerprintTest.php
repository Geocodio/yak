<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TurnState;
use App\Models\CoOwnerRequest;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Push\Widgets\WidgetFingerprint;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Test person']);
});

function startRunning(Thread $thread, TurnState $state = TurnState::Running): TaskRun
{
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => $state]);
}

it('changes when a thread starts or stops running', function () {
    $thread = Thread::factory()->create(['created_by_user_id' => $this->user->id]);
    $idle = WidgetFingerprint::for($this->user);

    $run = startRunning($thread);
    $running = WidgetFingerprint::for($this->user);

    $run->update(['turn_state' => TurnState::Interrupted]);

    expect($running)->not->toBe($idle)
        ->and(WidgetFingerprint::for($this->user))->toBe($idle);
});

it('changes when the needs-you count changes, also through a task', function () {
    $before = WidgetFingerprint::for($this->user);

    $task = YakTask::factory()->awaitingCoOwner()->create();
    CoOwnerRequest::factory()->create([
        'task_id' => $task->id,
        'status' => CoOwnerRequestStatus::Pending,
        'asked_user_ids' => [$this->user->id],
    ]);

    expect(WidgetFingerprint::for($this->user))->not->toBe($before);
});

it('does not change for another person\'s private thread', function () {
    $before = WidgetFingerprint::for($this->user);
    $other = User::factory()->create();

    $thread = privateThreadOf($other);
    startRunning($thread);

    expect(WidgetFingerprint::for($this->user))->toBe($before);
});

it('does not change when only a title changes', function () {
    $thread = Thread::factory()->create(['created_by_user_id' => $this->user->id, 'title' => 'Retry failed uploads']);
    $before = WidgetFingerprint::for($this->user);

    $thread->update(['title' => 'Tidy the README']);

    expect(WidgetFingerprint::for($this->user))->toBe($before);
});

it('reads a running sub-thread', function () {
    $parent = Thread::factory()->create(['created_by_user_id' => $this->user->id]);
    $child = Thread::factory()->child($parent)->create(['created_by_user_id' => $this->user->id]);
    $before = WidgetFingerprint::for($this->user);

    startRunning($child);

    expect(WidgetFingerprint::for($this->user))->not->toBe($before);
});
