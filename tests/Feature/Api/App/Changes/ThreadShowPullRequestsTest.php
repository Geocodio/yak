<?php

use App\Enums\RepositoryRole;
use App\Models\ThreadRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = threadWithChanges($this->owner);
});

it('lists no pull requests before one is open', function () {
    $this->getJson(appThreadPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('pullRequests', []);
});

it('lists every pull request in merge order with its state and checks', function () {
    $this->thread->repositories()->where('repo', 'example/api')->update([
        'pull_request_number' => 41, 'pull_request_url' => 'https://github.com/example/api/pull/41', 'pull_request_state' => 'open',
        'merge_position' => 2, 'ci_status' => 'running',
    ]);
    ThreadRepository::factory()->create([
        'thread_id' => $this->thread->id, 'repo' => 'example/web', 'role' => RepositoryRole::Changes,
        'pull_request_number' => 12, 'pull_request_url' => 'https://github.com/example/web/pull/12', 'pull_request_state' => 'merged',
        'merge_position' => 1, 'ci_status' => 'failing',
    ]);
    ThreadRepository::factory()->create([
        'thread_id' => $this->thread->id, 'repo' => 'example/docs', 'role' => RepositoryRole::Context,
        'pull_request_number' => 3, 'pull_request_url' => 'https://github.com/example/docs/pull/3',
    ]);

    $response = $this->getJson(appThreadPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonCount(2, 'pullRequests')
        ->assertJsonPath('pullRequests.0.repository', 'example/web')
        ->assertJsonPath('pullRequests.0.state', 'merged')
        ->assertJsonPath('pullRequests.0.checks', 'failing')
        ->assertJsonPath('pullRequests.1.number', 41)
        ->assertJsonPath('pullRequests.1.state', 'open')
        ->assertJsonPath('pullRequests.1.checks', 'pending');

    ContractFixture::assertMatches('app', 'thread-pull-requests', ['pullRequests' => $response->json('pullRequests')]);
});

it('sends null checks when none were seen and treats an unrecorded state as open', function () {
    $this->thread->repositories()->where('repo', 'example/api')->update([
        'pull_request_number' => 41, 'pull_request_url' => 'https://github.com/example/api/pull/41', 'pull_request_state' => null, 'ci_status' => null,
    ]);

    $this->getJson(appThreadPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('pullRequests.0.state', 'open')
        ->assertJsonPath('pullRequests.0.checks', null);
});

it('never shows the pull requests of a hidden thread', function () {
    $hidden = privateThreadOf($this->owner);
    $hidden->repositories()->update(['pull_request_number' => 9, 'pull_request_url' => 'https://github.com/example/api/pull/9']);
    $other = User::factory()->create();

    $this->getJson(appThreadPath($hidden), phoneHeadersFor($other))->assertNotFound()
        ->assertJsonMissingPath('pullRequests');
});
