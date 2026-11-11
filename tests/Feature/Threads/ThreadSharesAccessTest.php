<?php

use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadAccess;

beforeEach(fn () => $this->withoutVite());

function sharedPrivateStack(): array
{
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $root = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->create(['parent_thread_id' => $root->id, 'root_thread_id' => $root->id]);
    ThreadShare::factory()->create(['thread_id' => $root->id, 'user_id' => $guest->id]);

    return [$owner, $guest, $root, $child];
}

test('a shared user sees the root and its children but cannot drive', function () {
    [$owner, $guest, $root, $child] = sharedPrivateStack();
    $access = app(ThreadAccess::class);

    expect($access->canSee($guest, $root))->toBeTrue()
        ->and($access->canSee($guest, $child))->toBeTrue()
        ->and($access->canDrive($guest, $root))->toBeFalse()
        ->and($access->canDrive($owner, $child))->toBeTrue()
        ->and($access->isOwner($owner, $root))->toBeTrue()
        ->and($access->isOwner($guest, $root))->toBeFalse()
        ->and($access->isOwner(null, $root))->toBeFalse();
});

test('an unshared user and an admin still cannot see it', function () {
    [, , $root] = sharedPrivateStack();
    $access = app(ThreadAccess::class);

    expect($access->canSee(User::factory()->create(), $root))->toBeFalse()
        ->and($access->canSee(User::factory()->create(['email' => 'admin@example.com']), $root))->toBeFalse();
});

test('visibleTo and the task scope include shared threads', function () {
    [, $guest, $root, $child] = sharedPrivateStack();
    $task = YakTask::factory()->create(['thread_id' => $child->id]);
    $stranger = User::factory()->create();

    expect(Thread::visibleTo($guest)->pluck('id')->all())->toBe([$root->id, $child->id])
        ->and(Thread::visibleTo($stranger)->count())->toBe(0);

    $this->actingAs($guest);
    expect(YakTask::pluck('id')->all())->toBe([$task->id])
        ->and(Thread::count())->toBe(2);
    $this->get("/threads/{$root->id}")->assertOk();

    $this->actingAs($stranger);
    expect(YakTask::count())->toBe(0);
    $this->get("/threads/{$root->id}")->assertNotFound();
});

test('deleting the user removes the share', function () {
    [, $guest] = sharedPrivateStack();

    $guest->delete();

    expect(ThreadShare::count())->toBe(0);
});
