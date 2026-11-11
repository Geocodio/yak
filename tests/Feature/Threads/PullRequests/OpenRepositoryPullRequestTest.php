<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadVisibility;
use App\Jobs\Threads\OpenRepositoryPullRequestJob;
use App\Jobs\Threads\RefreshSiblingBlocksJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\PullRequests\ThreadPullRequestSync;
use App\Services\Threads\PullRequests\ThreadRepositoryPullRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

function publishFixture(): array
{
    Repository::factory()->create(['slug' => 'acme/api', 'github_full_name' => 'acme/api', 'default_branch' => 'main']);
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web', 'default_branch' => 'main', 'ci_system' => 'github_actions']);
    Repository::factory()->create(['slug' => 'acme/docs', 'github_full_name' => 'acme/docs', 'default_branch' => 'main']);
    $thread = Thread::factory()->create(['title' => 'Rename the widget', 'sandbox_state' => ThreadSandboxState::Running]);
    $primary = ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/api', 'branch' => 'yak/rename']);
    $web = ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/web', 'sandbox_path' => '/workspace/web']);
    $docs = ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs', 'sandbox_path' => '/workspace/docs']);
    YakTask::factory()->create(['thread_id' => $thread->id, 'result_summary' => "Renamed the widget everywhere.\n\nMore detail."]);

    return [$thread, $primary, $web, $docs];
}

/** Queues the exec answers for one repo: untracked files, commits ahead, push. */
function queueRepoAnswers(SandboxManager $sandboxes, string $files, string $ahead): void
{
    $sandboxes->queueExecResult(Process::result($files));
    if (trim($files) !== '') {
        $sandboxes->queueExecResult(Process::result());
        $sandboxes->queueExecResult(Process::result());
    }
    $sandboxes->queueExecResult(Process::result($ahead));
    if ((int) $ahead > 0) {
        $sandboxes->queueExecResult(Process::result());
    }
}

function execCommands(SandboxManager $sandboxes): Collection
{
    return collect($sandboxes->calls)->where('operation', 'exec')->map(fn ($call) => implode(' ', $call['arguments']['command']));
}

it('pushes only changes repos that have commits and assigns a branch', function () {
    Queue::fake();
    [$thread, , $web, $docs] = publishFixture();
    $sandboxes = app(SandboxManager::class);
    queueRepoAnswers($sandboxes, "src/Widget.php\n.env", '2');

    app(ThreadRepositoryPullRequests::class)->publishChangedRepositories($thread);

    $commands = execCommands($sandboxes);
    expect($web->refresh()->branch)->toBe('yak/rename-web')->and($web->ci_status)->toBe('running')
        ->and($commands->first(fn ($command) => str_contains($command, ' add ')))->toContain('src/Widget.php')->not->toContain('.env')
        ->and($commands->first(fn ($command) => str_contains($command, ' push ')))->toContain('-C /workspace/web push origin HEAD:refs/heads/yak/rename-web')
        ->and($commands->filter(fn ($command) => str_contains($command, '/workspace/docs')))->toBeEmpty()
        ->and($docs->refresh()->branch)->toBeNull();
    Queue::assertNothingPushed();
});

it('gives a changes repo without commits no push and no state', function () {
    [$thread, , $web] = publishFixture();
    $sandboxes = app(SandboxManager::class);
    queueRepoAnswers($sandboxes, '', '0');

    app(ThreadRepositoryPullRequests::class)->publishChangedRepositories($thread);

    expect(execCommands($sandboxes)->filter(fn ($command) => str_contains($command, ' push ')))->toBeEmpty()
        ->and($web->refresh()->branch)->toBeNull()->and($web->ci_status)->toBeNull();
});

it('opens a repo with ci_system none without waiting for CI', function () {
    Queue::fake();
    [$thread, , $web] = publishFixture();
    Repository::query()->where('slug', 'acme/web')->update(['ci_system' => 'none']);
    queueRepoAnswers(app(SandboxManager::class), '', '1');

    app(ThreadRepositoryPullRequests::class)->publishChangedRepositories($thread);

    Queue::assertPushed(OpenRepositoryPullRequestJob::class, fn ($job) => $job->threadRepositoryId === $web->id);
});

function mockGitHub(?array $existing, int $creates): void
{
    $github = Mockery::mock(GitHubAppService::class);
    app()->instance(GitHubAppService::class, $github);
    $github->shouldReceive('findOpenPullRequestForBranch')->andReturn($existing);
    $github->shouldReceive('createPullRequest')->times($creates)->withArgs(fn ($installation, $repo, $data) => $repo === 'acme/web' && $data['title'] === 'Renamed the widget everywhere.' && $data['base'] === 'main' && $data['head'] === 'yak/rename-web')
        ->andReturn(['number' => 9, 'html_url' => 'https://github.com/acme/web/pull/9', 'title' => 'Renamed the widget everywhere.']);
}

it('opens the pull request once and records it on the row', function () {
    Queue::fake();
    [, , $web] = publishFixture();
    $web->forceFill(['branch' => 'yak/rename-web', 'ci_status' => 'passing'])->save();
    mockGitHub(null, 1);

    (new OpenRepositoryPullRequestJob($web->id))->handle(app(GitHubAppService::class), app(ThreadPullRequestSync::class));
    (new OpenRepositoryPullRequestJob($web->id))->handle(app(GitHubAppService::class), app(ThreadPullRequestSync::class));

    expect($web->refresh())->pull_request_number->toBe(9)->pull_request_state->toBe('open');
    Queue::assertPushed(RefreshSiblingBlocksJob::class, 1);
});

it('reuses a pull request that already exists for the branch', function () {
    Queue::fake();
    [, , $web] = publishFixture();
    $web->forceFill(['branch' => 'yak/rename-web'])->save();
    mockGitHub(['number' => 5, 'html_url' => 'https://github.com/acme/web/pull/5', 'title' => 'x'], 0);

    (new OpenRepositoryPullRequestJob($web->id))->handle(app(GitHubAppService::class), app(ThreadPullRequestSync::class));

    expect($web->refresh()->pull_request_number)->toBe(5);
});

it('never opens a pull request for a context repo', function () {
    [, , , $docs] = publishFixture();
    $docs->forceFill(['branch' => 'yak/rename-docs'])->save();
    app()->instance(GitHubAppService::class, Mockery::mock(GitHubAppService::class)->shouldNotReceive('createPullRequest')->getMock());

    (new OpenRepositoryPullRequestJob($docs->id))->handle(app(GitHubAppService::class), app(ThreadPullRequestSync::class));

    expect($docs->refresh()->pull_request_url)->toBeNull();
});

it('requires the primary pull request acknowledgement for a private thread', function () {
    Queue::fake();
    [$thread, $primary, $web] = publishFixture();
    $thread->forceFill(['visibility' => ThreadVisibility::Private, 'owner_user_id' => User::factory()->create()->id])->save();
    $web->forceFill(['branch' => 'yak/rename-web'])->save();
    mockGitHub(null, 1);
    $run = fn () => (new OpenRepositoryPullRequestJob($web->id))->handle(app(GitHubAppService::class), app(ThreadPullRequestSync::class));

    $run();
    expect($web->refresh()->pull_request_url)->toBeNull();

    $primary->forceFill(['pull_request_url' => 'https://github.com/acme/api/pull/1', 'pull_request_number' => 1, 'pull_request_state' => 'open'])->save();
    $run();
    expect($web->refresh()->pull_request_number)->toBe(9);
});
