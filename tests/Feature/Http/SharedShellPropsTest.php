<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

test('shares the mine list and needsYouCount', function () {
    $user = User::factory()->create(['has_seen_setup_card_at' => now()]);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)->get(route('tasks'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threadList.threads.0.id', $thread->id)
            ->where('nav.needsYouCount', 0)
            ->has('nav.activeTaskCount'));
});

test('does not share a private thread of another user', function () {
    $user = User::factory()->create(['has_seen_setup_card_at' => now()]);
    $secret = Thread::factory()->private(User::factory()->create())->create(['title' => 'Secret plan']);

    $response = $this->actingAs($user)->get(route('tasks'));

    expect($response->getContent())->not->toContain('Secret plan');
    $response->assertInertia(fn (Assert $page) => $page->where('threadList.threads', []));
});

test('guests get null', function () {
    $shared = (new HandleInertiaRequests)->share(Request::create('/login'));

    expect(value($shared['threadList']))->toBeNull()
        ->and(value($shared['nav'])['needsYouCount'])->toBe(0);
});
