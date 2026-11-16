<?php

use App\Enums\RepositoryRole;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadSessionKind;
use App\Enums\ThreadVisibility;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadSession;
use App\Models\YakTask;

it('returns the most recent main session as the active session', function () {
    $thread = Thread::factory()->create();
    $first = ThreadSession::factory()->create(['thread_id' => $thread->id]);
    ThreadSession::factory()->fork($first->session_id)->create(['thread_id' => $thread->id]);
    $latest = ThreadSession::factory()->create(['thread_id' => $thread->id]);

    expect($thread->activeSession()?->is($latest))->toBeTrue()
        ->and($latest->kind)->toBe(ThreadSessionKind::Main);
});

it('has no active session before the first turn', function () {
    expect(Thread::factory()->create()->activeSession())->toBeNull();
});

it('picks the primary repository among several', function () {
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->role(RepositoryRole::Context)->create(['thread_id' => $thread->id, 'repo' => 'acme/docs']);
    $primary = ThreadRepository::factory()->create(['thread_id' => $thread->id]);

    expect($thread->primaryRepository?->is($primary))->toBeTrue()
        ->and($thread->repositories)->toHaveCount(2);
});

it('links tasks, runs, parent and children', function () {
    $parent = Thread::factory()->create();
    $child = Thread::factory()->child($parent)->create();
    $task = YakTask::factory()->create(['thread_id' => $parent->id]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $parent->id]);

    expect($parent->tasks->modelKeys())->toBe([$task->id])
        ->and($parent->runs->modelKeys())->toBe([$run->id])
        ->and($child->parent?->is($parent))->toBeTrue()
        ->and($parent->children->modelKeys())->toBe([$child->id])
        ->and($task->thread?->is($parent))->toBeTrue()
        ->and($run->thread?->is($parent))->toBeTrue();
});

it('lets a sub-thread inherit visibility and owner from its root', function () {
    $root = Thread::factory()->private()->create();
    $child = Thread::factory()->child($root)->create();
    $grandchild = Thread::factory()->child($child)->create();

    // A stale copy on the child never overrides the root.
    $grandchild->forceFill(['visibility' => ThreadVisibility::Team, 'owner_user_id' => null]);

    expect($grandchild->root_thread_id)->toBe($root->id)
        ->and($grandchild->effectiveVisibility())->toBe(ThreadVisibility::Private)
        ->and($grandchild->effectiveOwnerUserId())->toBe($root->owner_user_id)
        ->and($grandchild->isPrivate())->toBeTrue()
        ->and($root->isRoot())->toBeTrue()
        ->and($child->isRoot())->toBeFalse();
});

it('treats a team root as team visible with no inherited owner', function () {
    $root = Thread::factory()->create();

    expect($root->effectiveVisibility())->toBe(ThreadVisibility::Team)
        ->and($root->effectiveOwnerUserId())->toBeNull()
        ->and($root->isPrivate())->toBeFalse();
});

it('builds the lifecycle factory states', function () {
    expect(Thread::factory()->paused()->create()->sandbox_state)->toBe(ThreadSandboxState::Paused)
        ->and(Thread::factory()->stopped()->create()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and(Thread::factory()->destroyed()->create()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and(Thread::factory()->closed()->create()->isOpen())->toBeFalse()
        ->and(Thread::factory()->ownedByDevice()->create()->owner_side)->toBe(ThreadOwnerSide::Device);
});

it('never exposes a Claude account secret in serialized output', function () {
    $account = ClaudeAccount::factory()->create();

    expect($account->toArray())->not->toHaveKey('secret')
        ->and($account->secret)->toStartWith('sk-ant-oat01-')
        ->and($account->getRawOriginal('secret'))->not->toStartWith('sk-ant-');
});
