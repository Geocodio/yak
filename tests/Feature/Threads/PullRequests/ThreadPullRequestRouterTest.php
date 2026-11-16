<?php

use App\Enums\ThreadStatus;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\PullRequests\ThreadPullRequestRouter;

beforeEach(function () {
    $this->repoA = Repository::factory()->create(['slug' => 'acme-api', 'github_full_name' => 'acme/api']);
    $this->repoB = Repository::factory()->create(['slug' => 'acme-web', 'github_full_name' => 'acme/web']);
    $this->thread = Thread::factory()->create();
    $this->router = new ThreadPullRequestRouter;
});

it('routes by repo and number', function () {
    $row = ThreadRepository::factory()->for($this->thread)->withPullRequest(7)->create(['repo' => 'acme-api']);

    $routed = $this->router->route('acme/api', 7, null);

    expect($routed->row->is($row))->toBeTrue()
        ->and($routed->thread->is($this->thread))->toBeTrue()
        ->and($routed->isPrimary)->toBeTrue();
});

it('routes the same number in another repo to that repo', function () {
    ThreadRepository::factory()->for($this->thread)->withPullRequest(3)->create(['repo' => 'acme-api']);
    $web = ThreadRepository::factory()->for($this->thread)->changes()->withPullRequest(3)->create(['repo' => 'acme-web']);

    $routed = $this->router->route('acme/web', 3, null);

    expect($routed->row->is($web))->toBeTrue()->and($routed->isPrimary)->toBeFalse();
});

it('falls back to the url when the number column is empty', function () {
    $row = ThreadRepository::factory()->for($this->thread)->create([
        'repo' => 'acme-api',
        'pull_request_url' => 'https://github.com/acme/api/pull/12',
    ]);

    expect($this->router->route('acme/api', 12, null)->row->is($row))->toBeTrue();
});

it('falls back to the branch when the head commit is the one Yak pushed', function () {
    $row = ThreadRepository::factory()->for($this->thread)->create(['repo' => 'acme-api', 'branch' => 'yak/fix-it', 'last_pushed_sha' => 'abc123']);

    expect($this->router->route('acme/api', 99, 'yak/fix-it', 'abc123')->row->is($row))->toBeTrue()
        ->and($this->router->route('acme/api', null, 'yak/fix-it', 'abc123')->row->is($row))->toBeTrue();
});

it('falls back to the branch when the head repository is the base repository', function () {
    $row = ThreadRepository::factory()->for($this->thread)->create(['repo' => 'acme-api', 'branch' => 'yak/fix-it']);

    expect($this->router->route('acme/api', null, 'yak/fix-it', 'other', 'Acme/API')->row->is($row))->toBeTrue();
});

it('never routes by branch name alone', function () {
    ThreadRepository::factory()->for($this->thread)->create(['repo' => 'acme-api', 'branch' => 'yak/fix-it', 'last_pushed_sha' => 'abc123']);

    expect($this->router->route('acme/api', null, 'yak/fix-it'))->toBeNull()
        ->and($this->router->route('acme/api', null, 'yak/fix-it', 'different'))->toBeNull()
        ->and($this->router->route('acme/api', null, 'yak/fix-it', 'different', 'evil/api'))->toBeNull();
});

it('never matches a context row by branch', function () {
    ThreadRepository::factory()->for($this->thread)->context()->create(['repo' => 'acme-web', 'branch' => 'yak/fix-it']);

    expect($this->router->route('acme/web', 5, 'yak/fix-it', 'abc', 'acme/web'))->toBeNull();
});

it('does not branch match a closed thread', function () {
    $closed = Thread::factory()->create(['status' => ThreadStatus::Closed]);
    ThreadRepository::factory()->for($closed)->create(['repo' => 'acme-api', 'branch' => 'yak/old', 'last_pushed_sha' => 'abc']);

    expect($this->router->route('acme/api', null, 'yak/old', 'abc', 'acme/api'))->toBeNull();
});

it('returns null for an unknown repo', function () {
    expect($this->router->route('nobody/nothing', 1, 'x'))->toBeNull();
});

it('still routes a closed thread by number', function () {
    $closed = Thread::factory()->create(['status' => ThreadStatus::Closed]);
    $row = ThreadRepository::factory()->for($closed)->withPullRequest(8)->create(['repo' => 'acme-api']);

    expect($this->router->route('acme/api', 8, null)->row->is($row))->toBeTrue();
});
