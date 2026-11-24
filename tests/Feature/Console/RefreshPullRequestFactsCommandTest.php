<?php

use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureFakeGitHubApp();
    config()->set('yak.channels.github.app_bot_login_override', 'yak-bot[bot]');
    Repository::factory()->create(['slug' => 'acme-app', 'github_full_name' => 'acme/app']);
});

function pullRequestRow(int $number, string $state = 'open'): ThreadRepository
{
    return ThreadRepository::factory()->for(Thread::factory()->create())->withPullRequest($number)->create(['repo' => 'acme-app', 'pull_request_state' => $state]);
}

function review(string $login, string $state, string $commit = 'abc'): array
{
    return ['user' => ['login' => $login], 'state' => $state, 'commit_id' => $commit];
}

it('fills facts for open pull requests', function () {
    $row = pullRequestRow(7);
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/pulls/7/reviews*' => Http::response([
            review('Mathias', 'APPROVED'),
            review('Mathias', 'COMMENTED', 'def'),
            review('teammate', 'CHANGES_REQUESTED', 'def'),
            review('yak-bot[bot]', 'APPROVED'),
        ]),
        'api.github.com/repos/acme/app/pulls/7' => Http::response([
            'number' => 7,
            'draft' => true,
            'head' => ['sha' => 'abc'],
            'created_at' => '2026-11-20T10:00:00Z',
            'mergeable_state' => 'clean',
        ]),
    ]);

    $this->artisan('yak:refresh-pull-request-facts')->assertSuccessful();

    $row->refresh();
    expect($row->pull_request_is_draft)->toBeTrue()
        ->and($row->pull_request_head_sha)->toBe('abc')
        ->and($row->pull_request_opened_at)->not->toBeNull()
        ->and($row->pull_request_mergeable_state)->toBe('clean')
        ->and($row->pull_request_reviews)->toBe([
            'mathias' => ['state' => 'approved', 'commitSha' => 'abc'],
            'teammate' => ['state' => 'changes_requested', 'commitSha' => 'def'],
        ]);
});

it('skips rows without a number and closed rows', function () {
    pullRequestRow(7);
    pullRequestRow(8, 'merged');
    ThreadRepository::factory()->for(Thread::factory()->create())->create(['repo' => 'acme-app', 'pull_request_state' => 'open']);
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/pulls/7/reviews*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/7' => Http::response(['number' => 7, 'head' => ['sha' => 'abc'], 'mergeable_state' => 'clean']),
    ]);

    $this->artisan('yak:refresh-pull-request-facts')->assertSuccessful();

    Http::assertSentCount(3);
});

it('keeps going when one pull request fails', function () {
    pullRequestRow(7);
    $second = pullRequestRow(8);
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/pulls/7*' => Http::response([], 500),
        'api.github.com/repos/acme/app/pulls/8/reviews*' => Http::response([]),
        'api.github.com/repos/acme/app/pulls/8' => Http::response(['number' => 8, 'head' => ['sha' => 'zzz'], 'mergeable_state' => 'dirty']),
    ]);

    $this->artisan('yak:refresh-pull-request-facts')->expectsOutputToContain('Failed')->assertSuccessful();

    expect($second->refresh()->pull_request_head_sha)->toBe('zzz');
});
