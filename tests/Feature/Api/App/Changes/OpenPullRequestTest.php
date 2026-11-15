<?php

use App\Enums\CoOwnerRequestStatus;
use App\Jobs\CreatePullRequestJob;
use App\Models\CoOwnerRequest;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\PullRequests\PullRequestOpening;
use App\Services\Threads\ThreadEventPublisher;
use App\Services\Threads\ThreadPublishGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    Queue::fake();
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = threadWithChanges($this->owner);
    $this->headers = phoneHeadersFor($this->owner);
});

it('starts opening a pull request', function () {
    $response = $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertStatus(202)->assertExactJson(['status' => 'opening']);

    Queue::assertPushed(CreatePullRequestJob::class, 1);
    expect(app(PullRequestOpening::class)->stateFor($this->thread)['state'])->toBe('opening')
        ->and(ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'thread')->latest('sequence')->first()->payload['pullRequestOpening'])->toBe('opening');
    ContractFixture::assertMatches('app', 'pull-request-opening', $response->json());
});

it('starts one job for two requests and none once the pull request is open', function () {
    Http::fake();
    $path = appThreadPath($this->thread, '/pull-request');

    $this->postJson($path, [], $this->headers)->assertStatus(202)->assertJsonPath('status', 'opening');
    Cache::flush();
    $this->postJson($path, [], $this->headers)->assertStatus(202)->assertJsonPath('status', 'opening');

    $this->thread->repositories()->where('repo', 'example/api')->update(['pull_request_number' => 41, 'pull_request_url' => 'https://github.com/example/api/pull/41']);
    $response = $this->postJson($path, [], $this->headers)->assertOk()->assertExactJson(['status' => 'open']);

    Queue::assertPushed(CreatePullRequestJob::class, 1);
    Http::assertNothingSent();
    ContractFixture::assertMatches('app', 'pull-request-open', $response->json());
});

it('claims once when two requests race', function () {
    $first = $this->thread->newQuery()->findOrFail($this->thread->id);
    $second = $this->thread->newQuery()->findOrFail($this->thread->id);

    expect(app(PullRequestOpening::class)->claim($first))->toBeTrue()
        ->and(app(PullRequestOpening::class)->claim($second))->toBeFalse();
});

it('answers held while a co-owner decides', function () {
    $task = $this->thread->pullRequestTask();
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->rootTask()->id, 'status' => CoOwnerRequestStatus::Pending]);

    $response = $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'held']);

    Queue::assertNothingPushed();
    ContractFixture::assertMatches('app', 'pull-request-held', $response->json());
});

it('starts again after a failure and after a stale opening', function (string $state, int $secondsAgo, int $jobs) {
    $this->thread->update(['pull_request_opening_state' => $state, 'pull_request_opening_at' => now()->subSeconds($secondsAgo)]);

    $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertStatus(202);

    Queue::assertPushed(CreatePullRequestJob::class, $jobs);
})->with([
    'failed' => ['failed', 30, 1],
    'stale opening' => ['opening', 601, 1],
    'fresh opening' => ['opening', 599, 0],
]);

it('asks for the acknowledgement on a private thread', function () {
    $thread = threadWithChanges($this->owner, ['public_id' => 'a1b2c3d4', 'visibility' => 'private']);

    $response = $this->postJson(appThreadPath($thread, '/pull-request'), [], $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    expect($response->json('details.errors.acknowledged_private_publish'))->toContain(ThreadPublishGuard::MESSAGE);
    Queue::assertNothingPushed();
    ContractFixture::assertMatches('app', 'error-private-publish', $response->json());

    $this->postJson(appThreadPath($thread, '/pull-request'), ['acknowledged_private_publish' => true], $this->headers)->assertStatus(202);
    Queue::assertPushed(CreatePullRequestJob::class, 1);
});

it('answers open and held on a private thread without the acknowledgement', function () {
    $open = threadWithChanges($this->owner, ['public_id' => 'a1b2c3d4', 'visibility' => 'private']);
    $open->repositories()->where('repo', 'example/api')->update(['pull_request_number' => 41, 'pull_request_url' => 'https://github.com/example/api/pull/41']);
    $this->postJson(appThreadPath($open, '/pull-request'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'open']);

    $held = threadWithChanges($this->owner, ['public_id' => 'e5f6a7b8', 'visibility' => 'private']);
    $task = $held->pullRequestTask();
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'root_task_id' => $task->rootTask()->id, 'status' => CoOwnerRequestStatus::Pending]);
    $this->postJson(appThreadPath($held, '/pull-request'), [], $this->headers)->assertOk()->assertExactJson(['status' => 'held']);

    Queue::assertNothingPushed();
});

it('records the opening and its event together', function () {
    $this->mock(ThreadEventPublisher::class, fn ($mock) => $mock->shouldReceive('recordThreadState')->andThrow(new RuntimeException('event store down')));
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers))->toThrow(RuntimeException::class);
    expect(app(PullRequestOpening::class)->stateFor($this->thread))->toBeNull()
        ->and(ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'thread')->exists())->toBeFalse();
    Queue::assertNothingPushed();

    $this->app->singleton(ThreadEventPublisher::class);

    $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertStatus(202);
    Queue::assertPushed(CreatePullRequestJob::class, 1);
    expect(ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'thread')->count())->toBe(1);
});

it('releases the claim and answers 503 when the job cannot be queued', function () {
    Queue::shouldReceive('push')->andThrow(new RuntimeException('redis down'));
    Queue::shouldReceive('connection')->andReturnSelf();
    $path = appThreadPath($this->thread, '/pull-request');

    $this->postJson($path, [], $this->headers)->assertStatus(503)->assertExactJson([
        'error' => 'unavailable',
        'message' => 'Yak could not start the pull request. Try again in a moment.',
    ]);

    expect(app(PullRequestOpening::class)->stateFor($this->thread))->toBeNull()
        ->and(app(PullRequestOpening::class)->flag($this->thread->id))->toBeNull();

    Queue::clearResolvedInstances();
    Queue::fake();
    $this->postJson($path, [], $this->headers)->assertStatus(202);
    Queue::assertPushed(CreatePullRequestJob::class, 1);
});

it('stores exactly one thread event for a claim', function () {
    $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertStatus(202);
    $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertStatus(202);

    expect(ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'thread')->count())->toBe(1);
});

it('refuses a thread with nothing to publish', function () {
    YakTask::query()->where('thread_id', $this->thread->id)->delete();

    $response = $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertStatus(409)
        ->assertJsonPath('error', 'nothing_to_publish')
        ->assertJsonPath('message', 'This thread has no changes to publish yet.');
    ContractFixture::assertMatches('app', 'error-nothing-to-publish', $response->json());
});

it('finishes the opening on success and keeps the reason on failure', function () {
    $task = $this->thread->pullRequestTask();
    $opening = app(PullRequestOpening::class);

    $opening->claim($this->thread);
    $opening->finish($task);
    expect($opening->stateFor($this->thread))->toBeNull()
        ->and(ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', 'thread')->latest('sequence')->first()->payload['pullRequestOpening'])->toBeNull();

    $opening->claim($this->thread);
    (new CreatePullRequestJob($task))->failed(new RuntimeException('GitHub PR creation failed: Validation Failed'));
    expect($opening->stateFor($this->thread))->toMatchArray(['state' => 'failed', 'error' => 'GitHub said: Validation Failed']);
});

it('waits at most five seconds for the thread lock', function (string $failingQuery) {
    DB::beforeExecuting(function (string $query) use ($failingQuery) {
        if (str_contains(str_replace(['"', '`'], '', $query), $failingQuery)) {
            throw lockWaitTimeout();
        }
    });

    $this->postJson(appThreadPath($this->thread, '/pull-request'), [], $this->headers)->assertStatus(503)->assertExactJson([
        'error' => 'unavailable',
        'message' => 'Yak is still handling another request for this thread. Try again in a moment.',
    ]);

    expect(app(PullRequestOpening::class)->stateFor($this->thread))->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'conditional update' => ['update threads set pull_request_opening_state'],
    'event lock' => ['select event_sequence from threads'],
]);

it('refuses a share recipient and answers a hidden thread like a missing one', function () {
    $recipient = shareThreadWith($this->thread, $this->owner);
    $this->postJson(appThreadPath($this->thread, '/pull-request'), [], phoneHeadersFor($recipient))->assertForbidden()->assertJsonPath('error', 'forbidden');

    $private = threadWithChanges($this->owner, ['public_id' => 'a1b2c3d4', 'visibility' => 'private']);
    $stranger = phoneHeadersFor(User::factory()->create(['name' => 'Stranger']));
    $hidden = $this->postJson(appThreadPath($private, '/pull-request'), ['acknowledged_private_publish' => true], $stranger)->assertNotFound();
    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/pull-request', ['acknowledged_private_publish' => true], $stranger)->assertNotFound();

    expect($hidden->getContent())->toBe($missing->getContent());
    Queue::assertNothingPushed();
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = appThreadPath($this->thread, '/pull-request');

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
