<?php

use App\Enums\ThreadShareMode;
use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

function sharing(): ThreadSharing
{
    return app(ThreadSharing::class);
}

test('everyone makes the thread a team thread and clears the pivot', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id]);

    sharing()->apply($thread, $owner, ThreadShareMode::Everyone);

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Team)
        ->and(ThreadShare::count())->toBe(0)
        ->and(sharing()->current($thread)['mode'])->toBe(ThreadShareMode::Everyone);
});

test('people keeps the thread private and replaces the rows', function () {
    $owner = User::factory()->create();
    [$a, $b, $c] = User::factory()->count(3)->create()->all();
    $thread = Thread::factory()->private($owner)->create();

    sharing()->apply($thread, $owner, ThreadShareMode::People, [$a->id, $b->id]);
    sharing()->apply($thread, $owner, ThreadShareMode::People, [$b->id, $c->id]);

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private)
        ->and(sharing()->current($thread))->toBe(['mode' => ThreadShareMode::People, 'userIds' => [$b->id, $c->id]]);
});

test('only me goes back to private and deletes rows', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->create(['owner_user_id' => $owner->id]);
    ThreadShare::factory()->create(['thread_id' => $thread->id]);

    sharing()->apply($thread, $owner, ThreadShareMode::OnlyMe);

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private)
        ->and(sharing()->current($thread))->toBe(['mode' => ThreadShareMode::OnlyMe, 'userIds' => []]);
});

test('owner id and unknown ids are dropped', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();

    sharing()->apply($thread, $owner, ThreadShareMode::People, [$owner->id, $guest->id, 999999]);

    expect(sharing()->current($thread)['userIds'])->toBe([$guest->id]);
});

test('a non-owner and an admin are refused', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $admin = User::factory()->create(['email' => 'admin@example.com']);

    expect(fn () => sharing()->apply($thread, User::factory()->create(), ThreadShareMode::Everyone))->toThrow(AuthorizationException::class)
        ->and(fn () => sharing()->apply($thread, $admin, ThreadShareMode::Everyone))->toThrow(AuthorizationException::class)
        ->and($thread->fresh()->visibility)->toBe(ThreadVisibility::Private);
});

test('a child resolves to its root so children share with the parent', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->create(['parent_thread_id' => $root->id, 'root_thread_id' => $root->id]);

    sharing()->apply($child, $owner, ThreadShareMode::People, [$guest->id]);

    expect(ThreadShare::pluck('thread_id')->all())->toBe([$root->id])
        ->and(sharing()->current($child)['userIds'])->toBe([$guest->id]);
});

test('a failure midway leaves visibility and rows unchanged', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    $share = ThreadShare::factory()->create(['thread_id' => $thread->id]);

    Event::listen('eloquent.updating: ' . Thread::class, fn () => throw new RuntimeException('boom'));

    expect(fn () => sharing()->apply($thread, $owner, ThreadShareMode::Everyone))->toThrow(RuntimeException::class);

    Event::forget('eloquent.updating: ' . Thread::class);

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private)
        ->and(ThreadShare::pluck('id')->all())->toBe([$share->id]);
});

test('calling twice with the same input is a no-op', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();

    sharing()->apply($thread, $owner, ThreadShareMode::People, [$guest->id]);
    sharing()->apply($thread, $owner, ThreadShareMode::People, [$guest->id]);

    expect(ThreadShare::count())->toBe(1);
});
