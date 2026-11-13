<?php

use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

test('the owner of a private thread sees ownership, share count and the publish warning', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->count(2)->create(['thread_id' => $thread->id]);

    $this->actingAs($owner)->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.isOwner', true)
        ->where('thread.sharedWithCount', 2)
        ->where('thread.startedOnDevice', false)
        ->where('publishWarning', true)
        ->where('canDrive', true));
});

test('a shared person is not the owner, sees no count and cannot drive', function () {
    $guest = User::factory()->create();
    $thread = Thread::factory()->private()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);

    $this->actingAs($guest)->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.isOwner', false)
        ->where('thread.sharedWithCount', 0)
        ->where('publishWarning', true)
        ->where('canDrive', false));
});

test('a team thread has no publish warning, and a device thread says so', function () {
    $thread = Thread::factory()->ownedByDevice()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('publishWarning', false)
        ->where('thread.startedOnDevice', true)
        ->where('thread.isOwner', false));
});
