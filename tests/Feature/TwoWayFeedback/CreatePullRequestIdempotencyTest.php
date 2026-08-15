<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Jobs\CreatePullRequestJob;
use App\Models\Repository;
use App\Models\YakTask;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 4242);
});

test('skips PR creation and comments when an open PR already exists for the branch', function () {
    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')
        ->once()
        ->andReturn(['number' => 9, 'html_url' => 'https://github.com/acme/web/pull/9']);
    $github->shouldNotReceive('createPullRequest');
    $github->shouldReceive('commentOnPullRequest')->once()
        ->withArgs(fn ($inst, $slug, $num, $body) => $num === 9 && str_contains($body, 'feedback'));

    Repository::factory()->create(['slug' => 'acme/web', 'path' => '/home/yak/repos/web']);
    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'branch_name' => 'yak/CSV-1',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'result_summary' => 'Handled the empty state',
    ]);

    (new CreatePullRequestJob($task))->handle($github);

    $task->refresh();
    expect($task->pr_number)->toBe(9)
        ->and($task->pr_url)->toBe('https://github.com/acme/web/pull/9');
});

test('creates a PR and stores pr_number when none exists', function () {
    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(null);
    $github->shouldReceive('createPullRequest')->once()
        ->andReturn(['number' => 12, 'html_url' => 'https://github.com/acme/web/pull/12']);
    $github->shouldReceive('addLabels')->once();

    Repository::factory()->create(['slug' => 'acme/web', 'path' => '/home/yak/repos/web']);
    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'acme/web',
        'branch_name' => 'yak/NEW-1',
        'pr_url' => null,
        'description' => 'Add a thing',
    ]);

    (new CreatePullRequestJob($task))->handle($github);

    $task->refresh();
    expect($task->pr_url)->toBe('https://github.com/acme/web/pull/12')
        ->and($task->pr_number)->toBe(12);
});

test('throws when an existing PR is returned without expected fields', function () {
    $github = $this->mock(GitHubAppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(['html_url' => 'https://github.com/acme/web/pull/9']); // missing 'number'
    $github->shouldNotReceive('createPullRequest');
    $github->shouldNotReceive('commentOnPullRequest');

    Repository::factory()->create(['slug' => 'acme/web', 'path' => '/home/yak/repos/web']);
    $task = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'branch_name' => 'yak/CSV-1',
    ]);

    expect(fn () => (new CreatePullRequestJob($task))->handle($github))
        ->toThrow(RuntimeException::class);
});
