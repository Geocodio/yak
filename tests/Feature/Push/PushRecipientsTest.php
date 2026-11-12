<?php

use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\PushRecipients;

function ids(array $users): array
{
    $ids = array_map(fn (User $user): int => $user->id, $users);
    sort($ids);

    return $ids;
}

function runDrivenBy(Thread $thread, ?User $driver): TaskRun
{
    return TaskRun::factory()->create(['thread_id' => $thread->id, 'driver_user_id' => $driver?->id]);
}

it('addresses a turn to its driver', function () {
    $driver = User::factory()->create();
    $run = runDrivenBy(Thread::factory()->create(), $driver);

    expect(ids(app(PushRecipients::class)->forTurn($run)))->toBe([$driver->id]);
});

it('addresses nobody for a turn without a driver', function () {
    expect(app(PushRecipients::class)->forTurn(runDrivenBy(Thread::factory()->create(), null)))->toBe([]);
});

it('addresses a blocked thread to its latest driver and owner, deduplicated', function () {
    $owner = User::factory()->create();
    $driver = User::factory()->create();
    $thread = Thread::factory()->create(['owner_user_id' => $owner->id]);
    runDrivenBy($thread, $owner);
    runDrivenBy($thread, $driver);

    expect(ids(app(PushRecipients::class)->forBlockedThread($thread)))->toBe(ids([$owner, $driver]));

    $solo = Thread::factory()->create(['owner_user_id' => $owner->id]);
    runDrivenBy($solo, $owner);

    expect(ids(app(PushRecipients::class)->forBlockedThread($solo)))->toBe([$owner->id]);
});

it('addresses held events, pull requests and stacks to the owner', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->create(['owner_user_id' => $owner->id]);
    $child = Thread::factory()->child($root)->create();
    $recipients = app(PushRecipients::class);

    expect(ids($recipients->forHeldEvent($child)))->toBe([$owner->id])
        ->and(ids($recipients->forPullRequest($child)))->toBe([$owner->id])
        ->and(ids($recipients->forStack($root)))->toBe([$owner->id]);
});

it('escalates a child question to the parent driver, else the root owner', function () {
    $owner = User::factory()->create();
    $driver = User::factory()->create();
    $root = Thread::factory()->create(['owner_user_id' => $owner->id]);
    $child = Thread::factory()->child($root)->create();
    $recipients = app(PushRecipients::class);

    expect(ids($recipients->forChildEscalation($child)))->toBe([$owner->id]);

    runDrivenBy($root, $driver);

    expect(ids($recipients->forChildEscalation($child->fresh())))->toBe([$driver->id]);
});

it('resolves a child of a private root to the root owner', function () {
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->child($root)->create();

    expect(ids(app(PushRecipients::class)->forChildEscalation($child)))->toBe([$owner->id]);
});

it('returns nothing for an ownerless stack', function () {
    expect(app(PushRecipients::class)->forStack(Thread::factory()->create()))->toBe([]);
});
