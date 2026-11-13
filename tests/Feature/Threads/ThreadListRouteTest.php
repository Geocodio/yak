<?php

use App\Models\Thread;
use App\Models\ThreadView;
use App\Models\User;

test('guests are redirected', function () {
    $this->get(route('threads.list'))->assertRedirect(route('login'));
    $this->postJson(route('threads.seen', 1))->assertUnauthorized();
});

test('returns the mine list by default', function () {
    $user = User::factory()->create();
    $mine = Thread::factory()->create(['created_by_user_id' => $user->id, 'title' => 'My thread']);
    Thread::factory()->create(['created_by_user_id' => User::factory()->create()->id, 'title' => 'Not mine']);

    $this->actingAs($user)->getJson(route('threads.list'))
        ->assertOk()
        ->assertJsonPath('threads.0.id', $mine->public_id)
        ->assertJsonCount(1, 'threads');
});

test('the everyone filter and search apply', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['title' => 'Fix the cache']);
    Thread::factory()->create(['title' => 'Other work']);

    $this->actingAs($user)->getJson(route('threads.list', ['filter' => 'everyone', 'search' => 'cache']))
        ->assertOk()
        ->assertJsonCount(1, 'threads')
        ->assertJsonPath('threads.0.title', 'Fix the cache');
});

test('rejects an unknown filter and a 101 character search with 422', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson(route('threads.list', ['filter' => 'bogus']))->assertUnprocessable();
    $this->getJson(route('threads.list', ['search' => str_repeat('a', 101)]))->assertUnprocessable();
});

test('seen creates then updates the view row', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();

    $this->actingAs($user)->postJson(route('threads.seen', $thread))->assertNoContent();
    $first = ThreadView::query()->where('user_id', $user->id)->where('thread_id', $thread->id)->firstOrFail();

    $this->travel(5)->minutes();
    $this->postJson(route('threads.seen', $thread))->assertNoContent();

    expect(ThreadView::query()->count())->toBe(1)
        ->and(ThreadView::query()->firstOrFail()->last_seen_at->greaterThan($first->last_seen_at))->toBeTrue();
});

test('seen on a private thread of someone else answers 404 and creates no row', function () {
    $thread = Thread::factory()->private(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())->postJson(route('threads.seen', $thread))->assertNotFound();

    expect(ThreadView::query()->count())->toBe(0);
});

test("list never includes another user's private thread", function () {
    $thread = Thread::factory()->private(User::factory()->create())->create();

    $this->actingAs(User::factory()->create())->getJson(route('threads.list', ['filter' => 'everyone']))
        ->assertOk()
        ->assertJsonMissing(['id' => $thread->public_id]);
});

test('the yak filter is accepted and the new thread data loads only on request', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['created_by_user_id' => null, 'title' => 'From Sentry']);

    $this->actingAs($user)->getJson(route('threads.list', ['filter' => 'yak']))
        ->assertOk()
        ->assertJsonPath('threads.0.title', 'From Sentry')
        ->assertJsonPath('hasAnyThreads', true);

    $this->actingAs($user)->get(route('tasks'))
        ->assertInertia(fn ($page) => $page->missing('newThread'));
});
