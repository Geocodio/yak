<?php

use App\Enums\RepositoryRole;
use App\Enums\ThreadKind;
use App\Enums\ThreadVisibility;
use App\Exceptions\StackRuleViolation;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildSpawner;

function stackParent(array $attributes = []): Thread
{
    $parent = Thread::factory()->create([
        'repo' => 'acme/widgets',
        'branch' => 'yak/parent-work',
        'claude_account_id' => ClaudeAccount::factory()->create()->id,
        ...$attributes,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $parent->id, 'repo' => 'acme/widgets', 'role' => RepositoryRole::Primary]);

    return $parent;
}

it('copies account, visibility and owner from a private root', function () {
    $owner = User::factory()->create();
    $parent = stackParent(['visibility' => ThreadVisibility::Private, 'owner_user_id' => $owner->id]);

    $child = app(ChildSpawner::class)->spawn($parent, 'Look Up Docs', 'Find the docs.', ThreadKind::Research, null);

    expect($child->visibility)->toBe(ThreadVisibility::Private)
        ->and($child->owner_user_id)->toBe($owner->id)
        ->and($child->claude_account_id)->toBe($parent->claude_account_id)
        ->and($child->parent_thread_id)->toBe($parent->id)
        ->and($child->root_thread_id)->toBe($parent->id)
        ->and($child->repo)->toBe('acme/widgets')
        ->and($child->name)->toBe('look-up-docs')
        ->and($child->kind)->toBe(ThreadKind::Research)
        ->and($child->brief)->toBe('Find the docs.')
        ->and($child->branch)->toBeNull()
        ->and($child->primaryRepository?->repo)->toBe('acme/widgets');
});

it('builds the child branch from the parent branch and name', function () {
    $parent = stackParent();

    $child = app(ChildSpawner::class)->spawn($parent, 'api-client', 'Build it.', ThreadKind::Implement, 'opus');

    expect($child->branch)->toBe('yak/yak/parent-work/api-client')
        ->and($child->model)->toBe('opus');
});

it('suffixes a clashing name', function () {
    $parent = stackParent();
    $spawner = app(ChildSpawner::class);

    $first = $spawner->spawn($parent, 'worker', 'One.', ThreadKind::Implement, null);
    $second = $spawner->spawn($parent, 'worker', 'Two.', ThreadKind::Implement, null);

    expect($first->name)->toBe('worker')->and($second->name)->toBe('worker-2');
});

it('creates a stack task for the turn machinery', function () {
    $driver = User::factory()->create();
    $parent = stackParent();
    $run = TaskRun::factory()->create(['thread_id' => $parent->id, 'driver_user_id' => $driver->id]);

    $child = app(ChildSpawner::class)->spawn($parent, 'helper', 'Do the thing.', ThreadKind::General, null, $run);
    $task = YakTask::query()->where('thread_id', $child->id)->sole();

    expect($task->source)->toBe(YakTask::SOURCE_STACK)
        ->and($task->external_id)->toStartWith('STACK-')
        ->and($task->description)->toBe('Do the thing.')
        ->and($task->started_by_user_id)->toBe($driver->id)
        ->and($child->spawned_by_task_run_id)->toBe($run->id);
});

it('refuses a child as spawner and a root kind', function () {
    $parent = stackParent();
    $child = Thread::factory()->child($parent)->create();

    expect(fn () => app(ChildSpawner::class)->spawn($child, 'x', 'y', ThreadKind::Implement, null))
        ->toThrow(StackRuleViolation::class)
        ->and(fn () => app(ChildSpawner::class)->spawn($parent, 'x', 'y', ThreadKind::Root, null))
        ->toThrow(StackRuleViolation::class)
        ->and(Thread::query()->withoutGlobalScopes()->where('parent_thread_id', $parent->id)->count())->toBe(1);
});

it('refuses a model outside the configured list', function () {
    $parent = stackParent();

    expect(fn () => app(ChildSpawner::class)->spawn($parent, 'worker', 'Do it.', ThreadKind::Implement, 'gpt-9'))
        ->toThrow(StackRuleViolation::class, 'Unknown model');
    expect(Thread::query()->where('parent_thread_id', $parent->id)->exists())->toBeFalse();
});

it('defaults the child model to the configured default', function () {
    $child = app(ChildSpawner::class)->spawn(stackParent(), 'worker', 'Do it.', ThreadKind::Implement, null);

    expect($child->model)->toBe(config('yak.sandbox.default_child_model'))->toBe('sonnet');
});
