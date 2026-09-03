<?php

use App\Enums\TaskStatus;
use App\Jobs\ClarificationReplyJob;
use App\Jobs\RunFollowUpJob;
use App\Models\PendingSteeringMessage;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('a clarification reply dispatches ClarificationReplyJob', function () {
    Queue::fake([ClarificationReplyJob::class]);
    $task = YakTask::factory()->create(['status' => TaskStatus::AwaitingClarification]);

    $this->post(route('tasks.messages.store', $task), ['message' => 'Convert in place'])
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('success', 'Reply sent. Yak is continuing the task.');

    Queue::assertPushed(ClarificationReplyJob::class);
});

test('a running task queues a steering message', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('tasks.messages.store', $task), ['message' => 'also handle IPv6'])
        ->assertSessionHas('success', 'Queued -- Yak will pick this up when the current run finishes.');

    expect(PendingSteeringMessage::where('root_task_id', $task->id)->count())->toBe(1);
});

test('an open-pr task creates a chained follow-up and dispatches RunFollowUpJob', function () {
    Queue::fake();

    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'repo' => 'web',
        'branch_name' => 'yak/CSV-1',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
    ]);

    $this->post(route('tasks.messages.store', $task), ['message' => 'Also handle the empty-state'])
        ->assertRedirect(route('tasks.show', $task))
        ->assertSessionHas('success', 'Sent to Yak. It will push changes to this PR.');

    expect(YakTask::where('parent_task_id', $task->id)->count())->toBe(1);
    Queue::assertPushed(RunFollowUpJob::class);
});

test('a merged pr creates nothing and dispatches nothing', function () {
    Queue::fake();
    $task = YakTask::factory()->merged()->create(['source' => 'dashboard', 'branch_name' => 'yak/M-1']);

    $this->post(route('tasks.messages.store', $task), ['message' => 'too late'])
        ->assertSessionHas('error', 'This conversation is closed.');

    expect(YakTask::where('parent_task_id', $task->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('the dashboard follow-up records the logged-in user as the author', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['name' => 'Mathias Hansen']));

    $task = YakTask::factory()->success()->create([
        'source' => 'dashboard',
        'repo' => 'web',
        'branch_name' => 'yak/AU-1',
        'pr_url' => 'https://github.com/acme/web/pull/12',
        'pr_number' => 12,
    ]);

    $this->post(route('tasks.messages.store', $task), ['message' => 'Trim the extras']);

    $child = YakTask::where('parent_task_id', $task->id)->first();

    expect($child)->not->toBeNull()
        ->and($child->author_name)->toBe('Mathias Hansen');
});

test('message is required', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('tasks.messages.store', $task), [])
        ->assertSessionHasErrors(['message']);
});
