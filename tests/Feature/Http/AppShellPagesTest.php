<?php

use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

test('tasks page still renders with the new shared props', function () {
    $user = User::factory()->create(['has_seen_setup_card_at' => now()]);

    $this->actingAs($user)->get(route('tasks'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('nav.activeTaskCount')
            ->has('nav.needsYouCount')
            ->has('threadList.threads'));
});

test('threads.show renders for the owner and 404s for others', function () {
    $owner = User::factory()->create(['has_seen_setup_card_at' => now()]);
    $other = User::factory()->create(['has_seen_setup_card_at' => now()]);
    $thread = Thread::factory()->private($owner)->create();

    $this->actingAs($owner)->get(route('threads.show', $thread))->assertOk();
    $this->actingAs($other)->get(route('threads.show', $thread))->assertNotFound();
});

test('the thread list on a page contains only visible threads', function () {
    $viewer = User::factory()->create(['has_seen_setup_card_at' => now()]);
    $visible = Thread::factory()->create(['created_by_user_id' => $viewer->id, 'title' => 'Visible work']);
    $secret = Thread::factory()->private(User::factory()->create())->create(['title' => 'Hidden work']);

    $response = $this->actingAs($viewer)->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('threadList.threads', 1)
            ->where('threadList.threads.0.id', $visible->id));

    expect($response->getContent())->not->toContain('Hidden work')
        ->and($secret->id)->not->toBe($visible->id);
});
