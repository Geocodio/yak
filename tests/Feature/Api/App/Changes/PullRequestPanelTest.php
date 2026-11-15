<?php

use App\Enums\RepositoryRole;
use App\Enums\ThreadOwnerSide;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    Queue::fake();
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = threadWithChanges($this->owner);
    $this->headers = phoneHeadersFor($this->owner);
    $this->api = $this->thread->repositories()->where('repo', 'example/api')->firstOrFail();
    $this->api->update([
        'pull_request_number' => 41, 'pull_request_state' => 'open', 'pull_request_title' => 'Retry failed uploads',
        'pull_request_url' => 'https://github.com/example/api/pull/41', 'merge_position' => 1,
        'ci_status' => 'passing', 'ci_checks' => [['name' => 'tests', 'state' => 'passing', 'durationSeconds' => 182]],
    ]);
    $this->web = ThreadRepository::factory()->create([
        'thread_id' => $this->thread->id, 'repo' => 'example/web', 'role' => RepositoryRole::Changes, 'branch' => 'yak/retry-failed-uploads',
        'pull_request_number' => 12, 'pull_request_state' => 'open', 'pull_request_title' => 'Retry failed uploads',
        'pull_request_url' => 'https://github.com/example/web/pull/12', 'merge_position' => 2, 'merge_reason' => 'It uses the new upload endpoint.',
        'ci_status' => 'failing', 'ci_checks' => [['name' => 'lint', 'state' => 'failing', 'durationSeconds' => 40, 'excerpt' => 'src/uploads.ts:3 unused import']],
    ]);
    $this->path = fn (string $suffix = ''): string => appThreadPath($this->thread, '/pull-requests' . $suffix);
});

it('shows a blocked panel with two pull requests', function () {
    $response = $this->getJson(($this->path)(), $this->headers)->assertOk()
        ->assertJsonPath('state', 'blocked')
        ->assertJsonPath('pullRequests.1.canAskToFix', true)
        ->assertJsonPath('pullRequests.0.canAskToFix', false)
        ->assertJsonPath('pullRequests.1.mergeAfter.number', 41)
        ->assertJsonPath('canDrive', true);

    ContractFixture::assertMatches('app', 'pull-requests', $response->json(), volatile: ['pullRequests.*.rowId']);
});

it('shows an empty panel with a context repository', function () {
    $thread = threadWithChanges($this->owner, ['public_id' => 'b2c3d4e5']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/docs', 'role' => RepositoryRole::Context]);

    $response = $this->getJson(appThreadPath($thread, '/pull-requests'), $this->headers)->assertOk()
        ->assertJsonPath('state', 'none')
        ->assertJsonPath('pullRequests', [])
        ->assertJsonPath('withoutPullRequest.1.reason', 'context');

    ContractFixture::assertMatches('app', 'pull-requests-none', $response->json());
});

it('saves the merge order and answers the same for a repeat', function () {
    $order = [$this->web->id, $this->api->id];

    foreach ([1, 2] as $attempt) {
        $response = $this->putJson(($this->path)('/order'), ['order' => $order], $this->headers)->assertOk()->assertExactJson(['status' => 'updated']);
    }

    expect($this->web->refresh()->merge_position)->toBe(1)
        ->and($this->api->refresh()->merge_position)->toBe(2);
    ContractFixture::assertMatches('app', 'pull-requests-order', $response->json());
});

it('refuses a merge order that does not fit', function (array $body, ?string $message) {
    $response = $this->putJson(($this->path)('/order'), $body === ['ids'] ? ['order' => [$this->api->id]] : $body, $this->headers)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    expect($response->json('details.errors.order'))->not->toBeEmpty();

    if ($message !== null) {
        expect($response->json('details.errors.order.0'))->toBe($message);
    }

    expect($this->api->refresh()->merge_position)->toBe(1);
})->with([
    'a missing id' => [['ids'], 'The order must list each pull request of this thread exactly once.'],
    'a duplicate' => [['order' => [1, 1]], 'The order must list each pull request of this thread exactly once.'],
    'fifty one ids' => [['order' => range(1, 51)], null],
    'not a list' => [['order' => 'x'], null],
    'nothing' => [[], null],
]);

it('asks Yak to fix failing CI once', function () {
    Queue::fake();
    $path = ($this->path)("/{$this->web->id}/fix");

    foreach ([1, 2] as $attempt) {
        $response = $this->postJson($path, [], $this->headers)->assertOk()->assertExactJson(['status' => 'sent']);
    }

    Queue::assertPushed(RetryRepositoryCiJob::class, 1);
    ContractFixture::assertMatches('app', 'pull-request-fix', $response->json());
});

it('refuses a fix that does not apply', function () {
    $response = $this->postJson(($this->path)("/{$this->api->id}/fix"), [], $this->headers)->assertStatus(409)
        ->assertJsonPath('error', 'ci_not_failing')
        ->assertJsonPath('message', 'CI is not failing on this pull request.');
    ContractFixture::assertMatches('app', 'error-ci-not-failing', $response->json());

    $closed = threadWithChanges($this->owner, ['public_id' => 'c3d4e5f6', 'status' => 'closed']);
    $closedRow = ThreadRepository::factory()->create(['thread_id' => $closed->id, 'repo' => 'example/web', 'role' => RepositoryRole::Changes, 'ci_status' => 'failing', 'pull_request_number' => 12, 'pull_request_state' => 'open', 'pull_request_url' => 'https://github.com/example/web/pull/12']);
    $this->postJson(appThreadPath($closed, "/pull-requests/{$closedRow->id}/fix"), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'thread_closed');

    $this->thread->update(['owner_side' => ThreadOwnerSide::Device]);
    $this->postJson(($this->path)("/{$this->web->id}/fix"), [], $this->headers)->assertStatus(409)->assertJsonPath('error', 'thread_owned_by_device');
    Queue::assertNothingPushed();
});

it('answers a fix for a row that is not a pull request of this thread with not found', function (string $which) {
    $otherThread = Thread::factory()->create(['owner_user_id' => $this->owner->id, 'created_by_user_id' => $this->owner->id]);
    $otherRow = ThreadRepository::factory()->create(['thread_id' => $otherThread->id, 'repo' => 'example/web', 'role' => RepositoryRole::Changes, 'ci_status' => 'failing', 'pull_request_number' => 3, 'pull_request_state' => 'open', 'pull_request_url' => 'https://github.com/example/web/pull/3']);
    $primary = $this->thread->repositories()->where('role', RepositoryRole::Primary)->where('id', '!=', $this->api->id)->first() ?? ThreadRepository::factory()->create(['thread_id' => $this->thread->id, 'repo' => 'example/lib', 'role' => RepositoryRole::Primary]);
    $id = ['another thread' => $otherRow->id, 'no pull request' => $primary->id, 'unknown' => 99999999999][$which];

    $this->postJson(($this->path)("/{$id}/fix"), [], $this->headers)->assertNotFound()->assertJsonPath('error', 'not_found');
    Queue::assertNothingPushed();
})->with(['another thread', 'no pull request', 'unknown']);

it('lets a share recipient read the panel but not change it', function () {
    $recipient = shareThreadWith($this->thread, $this->owner);
    $headers = phoneHeadersFor($recipient);

    $this->getJson(($this->path)(), $headers)->assertOk()->assertJsonPath('canDrive', false)->assertJsonPath('pullRequests.1.canAskToFix', false);
    $this->putJson(($this->path)('/order'), ['order' => [$this->web->id, $this->api->id]], $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');
    $this->postJson(($this->path)("/{$this->web->id}/fix"), [], $headers)->assertForbidden()->assertJsonPath('error', 'forbidden');

    Queue::assertNothingPushed();
    expect($this->api->refresh()->merge_position)->toBe(1);
});

it('answers a hidden thread exactly like a missing one', function () {
    $private = threadWithChanges($this->owner, ['public_id' => 'a1b2c3d4', 'visibility' => 'private']);
    $row = $private->repositories()->first();
    $stranger = phoneHeadersFor(User::factory()->create(['name' => 'Stranger']));

    foreach ([['GET', '', []], ['PUT', '/order', ['order' => [$row->id]]], ['POST', "/{$row->id}/fix", []]] as [$method, $suffix, $body]) {
        $hidden = $this->json($method, appThreadPath($private, '/pull-requests' . $suffix), $body, $stranger)->assertNotFound();
        $missing = $this->json($method, '/api/app/v1/threads/zzzzzzzz/pull-requests' . $suffix, $body, $stranger)->assertNotFound();

        expect($hidden->getContent())->toBe($missing->getContent());
    }
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = ($this->path)();

    $this->getJson($path, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson($path, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->getJson($path, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
