<?php

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Events\ThreadSandboxSlotFreed;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Stacks\ChildAwakeLimit;
use App\Services\Stacks\ChildSpawner;
use App\Services\Threads\Contracts\SessionFileSync;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->withoutVite();
    $account = ClaudeAccount::factory()->create();
    $this->parent = Thread::factory()->create([
        'branch' => 'yak/parent',
        'sandbox_container' => 'yak-thread-parent',
        'sandbox_state' => ThreadSandboxState::Running,
        'claude_account_id' => $account->id,
    ]);
    ThreadRepository::factory()->create(['thread_id' => $this->parent->id, 'repo' => $this->parent->repo]);
});

function awakeChildren(Thread $parent, int $count): void
{
    Thread::factory()->child($parent)->count($count)->create(['sandbox_state' => ThreadSandboxState::Running]);
}

function spawnAndStart(Thread $parent, string $name, ThreadKind $kind): Thread
{
    $child = app(ChildSpawner::class)->spawn($parent, $name, 'Do the thing.', $kind, null);
    app(ChildSpawner::class)->start($child);

    return $child->fresh();
}

it('queues the fourth implement child behind the default limit of three', function () {
    awakeChildren($this->parent, 3);

    $child = spawnAndStart($this->parent, 'fourth', ThreadKind::Implement);

    expect($child->queued_for_slot_at)->not->toBeNull()
        ->and(TaskRun::query()->where('thread_id', $child->id)->exists())->toBeFalse();
});

it('does not queue research children', function () {
    awakeChildren($this->parent, 3);

    $child = spawnAndStart($this->parent, 'scout', ThreadKind::Research);

    expect($child->queued_for_slot_at)->toBeNull();
});

it('starts the queued child when a slot frees', function () {
    awakeChildren($this->parent, 3);
    $queued = spawnAndStart($this->parent, 'fourth', ThreadKind::Implement);

    app(ChildAwakeLimit::class)->startNextQueued($this->parent);
    expect($queued->fresh()->queued_for_slot_at)->not->toBeNull();

    Thread::query()->where('parent_thread_id', $this->parent->id)->where('sandbox_state', ThreadSandboxState::Running)->first()
        ->update(['sandbox_state' => ThreadSandboxState::Destroyed]);
    app(ChildAwakeLimit::class)->startNextQueued($this->parent);

    expect($queued->fresh()->queued_for_slot_at)->toBeNull()
        ->and(TaskRun::query()->where('thread_id', $queued->id)->exists())->toBeTrue();
});

it('clamps the stepper to the person awake cap', function () {
    config(['yak.threads.person_awake_cap' => 4]);
    $limit = app(ChildAwakeLimit::class);

    $limit->set($this->parent, 99);
    expect($this->parent->fresh()->child_awake_limit)->toBe(4);

    $limit->set($this->parent, 0);
    expect($this->parent->fresh()->child_awake_limit)->toBe(1);
});

it('updates the limit through the route', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('threads.stack.limit', $this->parent), ['limit' => 2])
        ->assertRedirect();

    expect($this->parent->fresh()->child_awake_limit)->toBe(2);
});

it('requires drive access', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    ThreadShare::query()->create(['thread_id' => $root->id, 'user_id' => $viewer->id]);

    $this->actingAs($viewer)->patch(route('threads.stack.limit', $root), ['limit' => 2])->assertForbidden();
    $this->actingAs(User::factory()->create())->patch(route('threads.stack.limit', $root), ['limit' => 2])->assertNotFound();

    expect($root->fresh()->child_awake_limit)->toBe(3);
});

it('starts the oldest queued child when a slot frees through the listener events', function (string $eventClass) {
    awakeChildren($this->parent, 3);
    $oldest = spawnAndStart($this->parent, 'fourth', ThreadKind::Implement);
    $newest = spawnAndStart($this->parent, 'fifth', ThreadKind::Implement);
    $oldest->update(['queued_for_slot_at' => now()->subMinute()]);

    $finished = Thread::query()->where('parent_thread_id', $this->parent->id)->where('sandbox_state', ThreadSandboxState::Running)->first();
    $finished->update(['sandbox_state' => ThreadSandboxState::Destroyed]);

    $event = $eventClass === ThreadSandboxSlotFreed::class
        ? new ThreadSandboxSlotFreed($finished)
        : new $eventClass(TaskRun::factory()->create(['thread_id' => $finished->id]));
    event($event);

    expect($oldest->fresh()->queued_for_slot_at)->toBeNull()
        ->and(TaskRun::query()->where('thread_id', $oldest->id)->exists())->toBeTrue()
        ->and($newest->fresh()->queued_for_slot_at)->not->toBeNull();
})->with([ThreadSandboxSlotFreed::class, TurnCompleted::class, TurnFailed::class]);
