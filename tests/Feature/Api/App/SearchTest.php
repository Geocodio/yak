<?php

use App\Models\PrReview;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use Tests\Support\ContractFixture;

it('finds threads, repositories and pull requests as one list', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['title' => 'Retry failed uploads', 'repo' => 'example/web', 'branch' => 'yak/retry-uploads', 'created_by_user_id' => $user->id]);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/web', 'pull_request_number' => 12, 'pull_request_url' => 'https://github.com/example/web/pull/12']);
    Repository::factory()->create(['slug' => 'example/uploads', 'name' => 'Uploads', 'git_url' => 'https://github.com/example/uploads.git']);

    $response = $this->getJson('/api/app/v1/search?q=upload', phoneHeadersFor($user))->assertOk()
        ->assertJsonPath('results.0.type', 'thread')
        ->assertJsonPath('results.0.id', $thread->public_id)
        ->assertJsonPath('results.0.threadId', $thread->public_id)
        ->assertJsonPath('results.0.subtitle', 'example/web / yak/retry-uploads');

    expect(array_column($response->json('results'), 'type'))->toContain('thread', 'repository', 'pull_request');
    ContractFixture::assertMatches('app', 'search', $response->json(), ['results.*.id', 'results.*.threadId', 'results.*.path']);
});

it('keeps another person private thread out of the results', function () {
    $owner = User::factory()->create();
    privateThreadOf($owner, ['title' => 'Secret uploads plan']);
    Thread::factory()->create(['title' => 'Public uploads plan']);

    $titles = fn (User $user): array => array_column($this->getJson('/api/app/v1/search?q=uploads', phoneHeadersFor($user))->assertOk()->json('results'), 'title');

    expect($titles($owner))->toContain('Secret uploads plan', 'Public uploads plan')
        ->and($titles(User::factory()->create()))->toBe(['Public uploads plan']);
});

it('caps the results like the palette', function () {
    $user = User::factory()->create();
    Thread::factory()->count(12)->create(['title' => 'Uploads work']);

    expect($this->getJson('/api/app/v1/search?q=uploads', phoneHeadersFor($user))->assertOk()->json('results'))->toHaveCount(10);
});

it('needs a query of at least two characters', function (string $query) {
    $this->getJson('/api/app/v1/search' . $query, phoneHeadersFor(User::factory()->create()))->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['q']]]);
})->with(['', '?q=', '?q=a', '?q=%20a%20']);

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $user = User::factory()->create();
    $this->getJson('/api/app/v1/search?q=uploads', ['X-Yak-Protocol' => '1'])->assertUnauthorized();

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson('/api/app/v1/search?q=uploads', $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->getJson('/api/app/v1/search?q=uploads', $computerHeaders)->assertUnauthorized();
});

it('throttles search', function () {
    $headers = phoneHeadersFor(User::factory()->create());

    foreach (range(1, 30) as $ignored) {
        $this->getJson('/api/app/v1/search?q=uploads', $headers)->assertOk();
    }

    $this->getJson('/api/app/v1/search?q=uploads', $headers)->assertTooManyRequests();
});

it('returns a reviewed pull request without a thread', function () {
    $user = User::factory()->create();
    PrReview::factory()->create(['repo' => 'example/billing', 'pr_number' => 7, 'pr_url' => 'https://github.com/example/billing/pull/7']);

    $result = $this->getJson('/api/app/v1/search?q=billing', phoneHeadersFor($user))->assertOk()->json('results.0');

    expect($result)->toMatchArray(['type' => 'pull_request', 'threadId' => null, 'url' => 'https://github.com/example/billing/pull/7'])
        ->and($result['path'])->toStartWith('/');
});

it('keeps the pull request of a hidden thread out of the results', function () {
    $owner = User::factory()->create();
    $thread = privateThreadOf($owner, ['title' => 'Hidden work', 'repo' => 'example/hidden']);
    ThreadRepository::factory()->create(['thread_id' => $thread->id, 'repo' => 'example/hidden', 'pull_request_number' => 5, 'pull_request_url' => 'https://github.com/example/hidden/pull/5']);

    $types = fn (User $user): array => array_column($this->getJson('/api/app/v1/search?q=hidden', phoneHeadersFor($user))->assertOk()->json('results'), 'type');

    expect($types($owner))->toContain('pull_request')->and($types(User::factory()->create()))->toBe([]);
});

it('counts searches in their own bucket', function () {
    $user = User::factory()->create();
    $headers = phoneHeadersFor($user);

    foreach (range(1, 31) as $ignored) {
        $this->getJson('/api/app/v1/search?q=uploads', $headers);
    }

    $this->getJson('/api/app/v1/search?q=uploads', $headers)->assertTooManyRequests();
    $this->getJson('/api/app/v1/threads/new/repository-access', $headers)->assertStatus(422);
});
