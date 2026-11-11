<?php

use App\Enums\EvictionReason;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\SandboxCapacity;

function capacity(): SandboxCapacity
{
    return app(SandboxCapacity::class);
}

function pausedThread(array $attributes = []): Thread
{
    return Thread::factory()->paused()->create(['last_activity_at' => now()->subHour(), ...$attributes]);
}

function runningThread(array $attributes = []): Thread
{
    return Thread::factory()->create([
        'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
        'sandbox_state' => ThreadSandboxState::Running,
        ...$attributes,
    ]);
}

beforeEach(function () {
    config(['yak.threads.awake_cap' => 3, 'yak.threads.person_awake_cap' => 2, 'yak.threads.eviction_grace_minutes' => 10, 'yak.threads.memory_floor_gb' => 8]);
});

test('under all limits can wake', function () {
    $thread = Thread::factory()->stopped()->create();

    expect(capacity()->canWake($thread))->toBeTrue()->and(capacity()->needsEviction($thread))->toBeNull();
});

test('global cap with no eligible paused sandbox cannot wake', function () {
    runningThread();
    runningThread();
    runningThread();

    $thread = Thread::factory()->stopped()->create();

    expect(capacity()->needsEviction($thread))->toBe(EvictionReason::GlobalCap)->and(capacity()->canWake($thread))->toBeFalse();
});

test('global cap with an old enough paused sandbox can wake', function () {
    runningThread();
    runningThread();
    $paused = pausedThread();

    $thread = Thread::factory()->stopped()->create();

    expect(capacity()->canWake($thread))->toBeTrue()
        ->and(capacity()->evictionCandidate($thread, EvictionReason::GlobalCap)?->id)->toBe($paused->id);
});

test('eviction grace protects a recently paused sandbox', function () {
    runningThread();
    runningThread();
    pausedThread(['last_activity_at' => now()->subMinutes(2)]);

    expect(capacity()->canWake(Thread::factory()->stopped()->create()))->toBeFalse();
});

test('person share blocks even when global slots are free', function () {
    $owner = User::factory()->create();
    runningThread(['owner_user_id' => $owner->id]);
    runningThread(['owner_user_id' => $owner->id]);

    $thread = Thread::factory()->stopped()->create(['owner_user_id' => $owner->id]);

    expect(capacity()->needsEviction($thread))->toBe(EvictionReason::PersonShare)->and(capacity()->canWake($thread))->toBeFalse();
});

test('person share evicts the owners own oldest paused sandbox first', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    pausedThread(['owner_user_id' => $other->id, 'last_activity_at' => now()->subDays(2)]);
    $older = pausedThread(['owner_user_id' => $owner->id, 'last_activity_at' => now()->subDays(1)]);
    pausedThread(['owner_user_id' => $owner->id, 'last_activity_at' => now()->subHours(2)]);

    $thread = Thread::factory()->stopped()->create(['owner_user_id' => $owner->id]);

    expect(capacity()->evictionCandidate($thread, EvictionReason::PersonShare)?->id)->toBe($older->id);
});

test('children count against the roots owner', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    runningThread(['owner_user_id' => $owner->id]);
    $child = Thread::factory()->child($root)->create([
        'sandbox_container' => 'yak-thread-child',
        'sandbox_state' => ThreadSandboxState::Running,
    ]);

    expect(capacity()->awakeCountFor($owner->id))->toBe(2)
        ->and($child->effectiveOwnerUserId())->toBe($owner->id);
});

test('a shared research sandbox counts once', function () {
    $parent = runningThread();
    Thread::factory()->child($parent)->create(['sandbox_container' => $parent->sandbox_container, 'sandbox_state' => ThreadSandboxState::Running]);

    expect(capacity()->awakeCount())->toBe(1);
});

test('ram floor counts arc through the manager number', function () {
    $manager = app(SandboxManager::class);
    $manager->memoryBytes = 7 * 1024 ** 3;
    $thread = Thread::factory()->stopped()->create();

    expect(capacity()->ramBelowFloor())->toBeTrue()->and(capacity()->needsEviction($thread))->toBe(EvictionReason::MemoryFloor);

    $manager->memoryBytes = 8 * 1024 ** 3;

    expect(capacity()->ramBelowFloor())->toBeFalse();
});

test('ram floor evicts oldest paused', function () {
    app(SandboxManager::class)->memoryBytes = 1024 ** 3;
    pausedThread(['last_activity_at' => now()->subHours(1)]);
    $oldest = pausedThread(['last_activity_at' => now()->subHours(5)]);

    expect(capacity()->evictionCandidate(Thread::factory()->stopped()->create(), EvictionReason::MemoryFloor)?->id)->toBe($oldest->id);
});

test('busy sandboxes are never candidates', function () {
    $busy = pausedThread(['last_activity_at' => now()->subDays(2)]);
    TaskRun::factory()->create(['thread_id' => $busy->id, 'turn_state' => TurnState::Running]);
    $idle = pausedThread();

    expect(capacity()->evictionCandidate(Thread::factory()->stopped()->create(), EvictionReason::GlobalCap)?->id)->toBe($idle->id);
});

test('destroy candidates are oldest first', function () {
    $newer = Thread::factory()->stopped()->create(['last_activity_at' => now()->subDay()]);
    $older = Thread::factory()->stopped()->closed()->create(['last_activity_at' => now()->subDays(5)]);
    pausedThread();

    expect(capacity()->destroyCandidates()->pluck('id')->all())->toBe([$older->id, $newer->id]);
});

test('pool watermark boundary at 80 percent', function () {
    $manager = app(SandboxManager::class);
    config(['yak.threads.pool_watermark_percent' => 80]);

    $manager->poolPercent = 80.0;
    expect(capacity()->poolOverWatermark())->toBeFalse();

    $manager->poolPercent = 80.1;
    expect(capacity()->poolOverWatermark())->toBeTrue();
});
