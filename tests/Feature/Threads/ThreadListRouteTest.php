<?php

use App\Enums\ThreadSource;
use App\Models\Thread;
use App\Models\ThreadRepository;
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

test('the everyone filter applies', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['title' => 'Fix the cache']);
    Thread::factory()->create(['title' => 'Other work']);

    $this->actingAs($user)->getJson(route('threads.list', ['filter' => 'everyone']))
        ->assertOk()
        ->assertJsonCount(2, 'threads');
});

test('rejects an unknown filter with 422', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson(route('threads.list', ['filter' => 'bogus']))->assertUnprocessable();
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

    $this->actingAs($user)->get(route('threads.log'))
        ->assertInertia(fn ($page) => $page->missing('newThread'));
});

test('the thread list endpoint narrows by sources and starters', function () {
    $user = User::factory()->create();
    $match = Thread::factory()->create(['source' => ThreadSource::Slack, 'created_by_user_id' => null, 'starter_name' => 'Jane: Doe']);
    Thread::factory()->create(['source' => ThreadSource::Slack, 'created_by_user_id' => null, 'starter_name' => 'Other']);
    Thread::factory()->create(['source' => ThreadSource::Linear, 'created_by_user_id' => null, 'starter_name' => 'Jane: Doe']);

    $this->actingAs($user)->getJson(route('threads.list', ['filter' => 'everyone', 'sources' => ['slack'], 'starters' => ['name:Jane: Doe']]))
        ->assertOk()
        ->assertJsonCount(1, 'threads')
        ->assertJsonPath('threads.0.id', $match->public_id)
        ->assertJsonStructure(['facets' => ['sources', 'starters']]);
});

test('an unknown source key returns an empty list, not the full list', function () {
    $user = User::factory()->create();
    Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)->getJson(route('threads.list', ['sources' => ['nope']]))
        ->assertOk()
        ->assertJsonCount(0, 'threads');
});

test('sources must be an array of strings', function () {
    $this->actingAs(User::factory()->create());

    $this->getJson(route('threads.list', ['sources' => 'slack']))->assertUnprocessable();
    $this->getJson(route('threads.list', ['starters' => 'yak']))->assertUnprocessable();
});

test('the review filter groups threads by review state', function () {
    $user = User::factory()->create(['github_login' => 'mathias']);
    $makeThread = function (array $repository) use ($user): Thread {
        $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
        ThreadRepository::factory()->create([
            'thread_id' => $thread->id,
            'pull_request_state' => 'open',
            'pull_request_number' => 1,
            'pull_request_head_sha' => 'head1',
            ...$repository,
        ]);

        return $thread;
    };
    $backToYou = $makeThread(['pull_request_reviews' => ['mathias' => ['state' => 'commented', 'commitSha' => 'head1']]]);
    $notReviewed = $makeThread([]);
    $approved = $makeThread(['pull_request_reviews' => ['mathias' => ['state' => 'approved', 'commitSha' => 'head1']]]);
    Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)->getJson(route('threads.list', ['filter' => 'review']))
        ->assertOk()
        ->assertJsonPath('reviewCount', 2)
        ->assertJsonPath('review.needsGitHubLink', false)
        ->assertJsonPath('review.backToYou.0.id', $backToYou->public_id)
        ->assertJsonPath('review.backToYou.0.review.state', 'back_to_you')
        ->assertJsonPath('review.notReviewed.0.id', $notReviewed->public_id)
        ->assertJsonPath('review.approved.0.id', $approved->public_id)
        ->assertJsonCount(0, 'review.withYak')
        ->assertJsonCount(0, 'threads');

    $this->getJson(route('threads.list'))->assertJsonPath('reviewCount', 2)->assertJsonMissingPath('review');
});

test('the review filter asks a viewer without a github login to link it', function () {
    $user = User::factory()->create(['github_login' => null]);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'pull_request_state' => 'open', 'pull_request_number' => 1]);

    $this->actingAs($user)->getJson(route('threads.list', ['filter' => 'review']))
        ->assertOk()
        ->assertJsonPath('review.needsGitHubLink', true)
        ->assertJsonCount(0, 'review.backToYou')
        ->assertJsonCount(0, 'review.notReviewed');
});
