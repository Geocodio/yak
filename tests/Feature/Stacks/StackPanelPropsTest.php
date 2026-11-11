<?php

use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('gives a child page its parent for the banner', function () {
    $parent = Thread::factory()->create(['title' => 'Root work', 'name' => 'root-work']);
    $child = Thread::factory()->child($parent)->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$child->id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.parentThreadId', $parent->id)
        ->where('stackParent.id', $parent->id)
        ->where('stackParent.title', 'Root work'));
});

it('gives a root page no parent', function () {
    $root = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$root->id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.parentThreadId', null)
        ->where('stackParent', null));
});
