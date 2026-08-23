<?php

use App\Enums\TaskStatus;
use App\Jobs\RunFollowUpJob;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Support\Facades\Queue;

test('creates a chained task copying branch/session/PR and dispatches RunFollowUpJob', function () {
    Queue::fake();

    $parent = YakTask::factory()->success()->create([
        'source' => 'linear',
        'repo' => 'web',
        'branch_name' => 'yak/CSV-1',
        'session_id' => 'sess_parent',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'linear_agent_session_id' => 'agent-sess-1',
        'external_id' => 'LINEAR-ENG-42',
    ]);

    $child = app(FollowUpTaskFactory::class)->create($parent, 'Handle the empty-state', 'dashboard');

    expect($child)->not->toBeNull()
        ->and($child->parent_task_id)->toBe($parent->id)
        ->and($child->branch_name)->toBe('yak/CSV-1')
        ->and($child->session_id)->toBe('sess_parent')
        ->and($child->pr_url)->toBe('https://github.com/acme/web/pull/9')
        ->and($child->pr_number)->toBe(9)
        ->and($child->repo)->toBe('web')
        ->and($child->source)->toBe('dashboard')
        ->and($child->linear_agent_session_id)->toBe('agent-sess-1')
        ->and($child->description)->toBe('Handle the empty-state')
        ->and($child->status->value)->toBe('pending');

    Queue::assertPushed(RunFollowUpJob::class, fn (RunFollowUpJob $job) => $job->task->id === $child->id);
});

test('returns null and dispatches nothing when the PR is merged or closed', function () {
    Queue::fake();

    $merged = YakTask::factory()->merged()->create(['branch_name' => 'yak/M-1']);
    $closed = YakTask::factory()->closedWithoutMerge()->create(['branch_name' => 'yak/C-1']);

    expect(app(FollowUpTaskFactory::class)->create($merged, 'too late', 'dashboard'))->toBeNull()
        ->and(app(FollowUpTaskFactory::class)->create($closed, 'too late', 'dashboard'))->toBeNull();

    Queue::assertNothingPushed();
});

test('chains onto the newest task in the conversation', function () {
    Queue::fake();

    $root = YakTask::factory()->success()->create(['branch_name' => 'yak/CHAIN-9', 'session_id' => 's0']);
    $head = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'branch_name' => 'yak/CHAIN-9',
        'session_id' => 's1',
        'pr_url' => $root->pr_url,
        'created_at' => now()->addMinute(),
    ]);

    $child = app(FollowUpTaskFactory::class)->create($root, 'next', 'dashboard');

    expect($child->parent_task_id)->toBe($head->id);
});

test('first follow-up external_id is rooted and not compounding', function () {
    Queue::fake();

    $root = YakTask::factory()->success()->create(['external_id' => 'LINEAR-ENG-42', 'branch_name' => 'yak/E-1']);

    $child = app(FollowUpTaskFactory::class)->create($root, 'do it', 'dashboard');

    expect($child->external_id)->toStartWith('LINEAR-ENG-42-followup-')
        ->and(substr_count($child->external_id, '-followup-'))->toBe(1);
});

test('chaining off an existing follow-up does not compound the external_id', function () {
    Queue::fake();

    $root = YakTask::factory()->success()->create(['external_id' => 'LINEAR-ENG-42', 'branch_name' => 'yak/E-2']);
    $followUp = app(FollowUpTaskFactory::class)->create($root, 'first', 'dashboard');

    // Now chain off the follow-up itself (passing the follow-up, not the root).
    $second = app(FollowUpTaskFactory::class)->create($followUp, 'second', 'dashboard');

    expect($second->external_id)->toStartWith('LINEAR-ENG-42-followup-')
        ->and(substr_count($second->external_id, '-followup-'))->toBe(1)
        ->and($second->parent_task_id)->toBe($followUp->id);
});

test('create() stores the author name on the child task', function () {
    $parent = YakTask::factory()->create([
        'status' => TaskStatus::Success,
        'branch_name' => 'yak/A-1',
        'pr_url' => 'https://github.com/acme/repo/pull/1',
        'pr_number' => 1,
    ]);

    Queue::fake();

    $child = app(FollowUpTaskFactory::class)
        ->create($parent, 'More tweaks', 'dashboard', authorName: 'Mathias');

    expect($child)->not->toBeNull()
        ->and($child->author_name)->toBe('Mathias');
});

test('create() leaves author name null when not provided', function () {
    $parent = YakTask::factory()->create([
        'status' => TaskStatus::Success,
        'branch_name' => 'yak/A-2',
        'pr_url' => 'https://github.com/acme/repo/pull/2',
        'pr_number' => 2,
    ]);

    Queue::fake();

    $child = app(FollowUpTaskFactory::class)->create($parent, 'More', 'slack');

    expect($child->author_name)->toBeNull();
});
