<?php

use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;

test('show lists the owner with the Owner role and a shared teammate with a null role', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();

    $people = collect($this->actingAs($owner)->getJson(route('threads.share.show', $thread))->assertOk()->json('people'))->keyBy('id');

    expect($people[$owner->id]['role'])->toBe('Owner')
        ->and($people[$teammate->id]['role'])->toBeNull();
});

test('removing a person from a private thread keeps it private and shares with the rest', function () {
    $owner = User::factory()->create();
    [$first, $second] = User::factory()->count(2)->create()->all();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $first->id]);
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $second->id]);

    $this->actingAs($owner)->putJson(route('threads.share.update', $thread), ['mode' => 'people', 'user_ids' => [$second->id]])->assertOk();

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private)
        ->and(ThreadShare::where('thread_id', $thread->id)->pluck('user_id')->all())->toBe([$second->id]);
});

test('removing the last person leaves the thread private and never team', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);

    $this->actingAs($owner)->putJson(route('threads.share.update', $thread), ['mode' => 'only_me', 'user_ids' => []])->assertOk();

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private)
        ->and(ThreadShare::where('thread_id', $thread->id)->count())->toBe(0);
});

test('a Remove replay on a private thread shares with nobody and stays private', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();

    $this->actingAs($owner)->putJson(route('threads.share.update', $thread), ['mode' => 'only_me', 'user_ids' => []])->assertOk();

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private)
        ->and(ThreadShare::where('thread_id', $thread->id)->count())->toBe(0);
});

test('a non-owner cannot change sharing', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $thread = Thread::factory()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $guest->id]);

    $this->actingAs($guest)->putJson(route('threads.share.update', $thread), ['mode' => 'everyone'])->assertForbidden();

    expect($thread->fresh()->visibility)->toBe(ThreadVisibility::Private)
        ->and(ThreadShare::where('thread_id', $thread->id)->count())->toBe(1);
});
