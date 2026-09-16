<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Jobs\CreatePullRequestJob;
use App\Models\Artifact;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\PullRequestBodySections;
use App\Services\PullRequestBodyUpdater;
use App\Services\ReviewReplyPoster;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 77);
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web', 'default_branch' => 'main']);
});

function followUpTask(array $overrides = []): YakTask
{
    $parent = YakTask::factory()->success()->create([
        'repo' => 'acme/web',
        'branch_name' => 'yak/CSV-1',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
    ]);

    return YakTask::factory()->awaitingCi()->create(array_merge([
        'parent_task_id' => $parent->id,
        'repo' => 'acme/web',
        'branch_name' => 'yak/CSV-1',
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'pr_number' => 9,
        'result_summary' => '- Added backoff',
    ], $overrides));
}

function existingPrGitHub(): MockInterface
{
    $github = test()->mock(GitHubAppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')
        ->once()
        ->andReturn(['number' => 9, 'html_url' => 'https://github.com/acme/web/pull/9']);
    $github->shouldReceive('commentOnPullRequest')
        ->once()
        ->withArgs(fn (int $installationId, string $repo, int $number, string $body): bool => str_contains($body, '- Added backoff'));

    return $github;
}

it('posts thread replies before the summary comment', function () {
    $github = existingPrGitHub();
    $poster = $this->mock(ReviewReplyPoster::class);
    $poster->shouldReceive('post')->once()->withArgs(fn (YakTask $task, string $repo, int $number): bool => $repo === 'acme/web' && $number === 9);
    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('setSections');

    (new CreatePullRequestJob(followUpTask(['review_replies' => [1 => 'Done.']])))->handle($github);
});

it('skips the summary comment when the run only produced replies', function () {
    $github = test()->mock(GitHubAppService::class);
    $github->shouldReceive('findOpenPullRequestForBranch')->once()->andReturn(['number' => 9, 'html_url' => 'https://github.com/acme/web/pull/9']);
    $github->shouldNotReceive('commentOnPullRequest');
    $this->mock(ReviewReplyPoster::class)->shouldReceive('post')->once();
    $this->mock(PullRequestBodyUpdater::class)->shouldNotReceive('setSections');

    (new CreatePullRequestJob(followUpTask(['result_summary' => null, 'review_replies' => [1 => 'Done.']])))->handle($github);
});

it('posts the comment and rewrites the description section when a rewrite is stored', function () {
    $github = existingPrGitHub();
    $updater = $this->mock(PullRequestBodyUpdater::class);
    $updater->shouldReceive('setSections')
        ->once()
        ->withArgs(fn (string $repo, int $number, array $sections): bool => $repo === 'acme/web'
            && $number === 9
            && array_keys($sections) === [PullRequestBodySections::DESCRIPTION]
            && str_contains($sections[PullRequestBodySections::DESCRIPTION], "## Summary\n\nWhole PR."))
        ->andReturn([PullRequestBodySections::DESCRIPTION]);

    $task = followUpTask(['pr_body_update' => "## Summary\n\nWhole PR."]);

    (new CreatePullRequestJob($task))->handle($github);
});

it('rebuilds the screenshots section from the follow-up task screenshots', function () {
    $github = existingPrGitHub();
    $updater = $this->mock(PullRequestBodyUpdater::class);
    $updater->shouldReceive('setSections')
        ->once()
        ->withArgs(fn (string $repo, int $number, array $sections): bool => array_keys($sections) === [PullRequestBodySections::SCREENSHOTS]
            && str_contains($sections[PullRequestBodySections::SCREENSHOTS], '### Screenshots')
            && str_contains($sections[PullRequestBodySections::SCREENSHOTS], '_After the fix_'))
        ->andReturn([PullRequestBodySections::SCREENSHOTS]);

    $task = followUpTask();
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'b.png', 'disk_path' => "{$task->id}/screenshots/b.png", 'size_bytes' => 1, 'caption' => 'After the fix']);

    (new CreatePullRequestJob($task))->handle($github);
});

it('inserts the screenshots section after the description when the PR body has no screenshots markers', function () {
    $github = existingPrGitHub();
    $updater = $this->mock(PullRequestBodyUpdater::class);
    $updater->shouldReceive('setSections')
        ->once()
        ->andReturn([]);
    $updater->shouldReceive('insertSectionAfter')
        ->once()
        ->withArgs(fn (string $repo, int $number, string $afterName, string $name, string $section): bool => $repo === 'acme/web'
            && $number === 9
            && $afterName === PullRequestBodySections::DESCRIPTION
            && $name === PullRequestBodySections::SCREENSHOTS
            && str_contains($section, '_After the fix_'))
        ->andReturn(true);

    $task = followUpTask();
    Artifact::create(['yak_task_id' => $task->id, 'type' => 'screenshot', 'role' => 'screenshot', 'filename' => 'b.png', 'disk_path' => "{$task->id}/screenshots/b.png", 'size_bytes' => 1, 'caption' => 'After the fix']);

    (new CreatePullRequestJob($task))->handle($github);
});

it('does not touch the body when there is no rewrite and no screenshots', function () {
    $github = existingPrGitHub();
    $updater = $this->mock(PullRequestBodyUpdater::class);
    $updater->shouldNotReceive('setSections');

    (new CreatePullRequestJob(followUpTask()))->handle($github);
});

it('logs and continues when the body update fails', function () {
    $github = existingPrGitHub();
    $updater = $this->mock(PullRequestBodyUpdater::class);
    $updater->shouldReceive('setSections')->once()->andThrow(new RuntimeException('boom'));
    Log::shouldReceive('channel')->with('yak')->andReturnSelf();
    Log::shouldReceive('warning')->once();

    (new CreatePullRequestJob(followUpTask(['pr_body_update' => 'new'])))->handle($github);
});
