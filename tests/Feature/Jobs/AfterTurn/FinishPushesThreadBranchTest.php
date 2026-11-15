<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\RepositoryRole;
use App\Enums\TaskRunKind;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Jobs\CreatePullRequestJob;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Teleport\EnsurePushedBranch;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\PullRequests\OpenPullRequest;
use App\Services\Threads\Repos\ThreadGitHubCredentials;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Support\LocalGitSandboxManager;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

beforeEach(function () {
    Queue::fake();
    $this->collaborators = fakeAfterTurnCollaborators();
    $this->collaborators['shell']->setCommitCount(0);
    $this->tempDir = sys_get_temp_dir() . '/yak-push-' . uniqid();
    mkdir($this->tempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->tempDir);
    app()->instance(SandboxManager::class, $this->git);

    $credentials = Mockery::mock(ThreadGitHubCredentials::class);
    $credentials->shouldReceive('ensureFresh');
    app()->instance(ThreadGitHubCredentials::class, $credentials);
});

afterEach(function () {
    File::deleteDirectory($this->tempDir);
});

function commitInWorkspace(object $test, string $file): void
{
    file_put_contents("{$test->git->workspace}/{$file}", "{$file}\n");
    $test->git->git(['add', '-A'], $test->git->workspace);
    $test->git->git(['-c', 'user.name=Yak', '-c', 'user.email=yak@example.com', 'commit', '-m', "Add {$file}"], $test->git->workspace);
}

function threadWithWorkspace(object $test, mixed $thread): void
{
    $thread->update(['branch' => $test->git->branch]);
    ThreadRepository::factory()->create([
        'thread_id' => $thread->id, 'repo' => $thread->repo, 'role' => RepositoryRole::Primary, 'branch' => $test->git->branch, 'sandbox_path' => $test->git->workspace,
    ]);
}

it('pushes the commits of a composer turn that answered a question, whatever the finisher decided', function () {
    [$task, $run, $thread] = endedTurn(kind: TaskRunKind::FollowUp);
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');
    $task->recordClarificationAnswers([], 'Use the staging bucket', 'Ada');
    threadWithWorkspace($this, $thread);
    commitInWorkspace($this, 'change.txt');

    expect($this->git->git(['branch', '--list', $this->git->branch]))->toBe('');

    runFinisher($run, FinishFollowUpTurnJob::class);

    expect($this->git->git(['rev-parse', "refs/heads/{$this->git->branch}"]))->toBe($this->git->git(['rev-parse', 'HEAD'], $this->git->workspace));
});

it('does not overwrite work a person pushed to the branch', function () {
    $this->git->pushFromElsewhere('theirs.txt', 'theirs');
    $remoteHead = $this->git->git(['rev-parse', "refs/heads/{$this->git->branch}"]);
    [, , $thread] = endedTurn();
    threadWithWorkspace($this, $thread);
    commitInWorkspace($this, 'mine.txt');

    expect(app(EnsurePushedBranch::class)->pushPrimaryIfBehind($thread))->toBeFalse()
        ->and($this->git->git(['rev-parse', "refs/heads/{$this->git->branch}"]))->toBe($remoteHead);
});

it('pushes an unpushed thread branch before opening the pull request', function () {
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready());
    app()->instance(ThreadSandbox::class, $sandbox);
    [$task, , $thread] = endedTurn();
    threadWithWorkspace($this, $thread);
    $task->update(['branch_name' => $this->git->branch]);
    commitInWorkspace($this, 'change.txt');

    $state = app(OpenPullRequest::class)->open($thread, User::factory()->create(), true);

    expect($state)->toBe('opening')
        ->and($this->git->git(['rev-parse', "refs/heads/{$this->git->branch}"]))->toBe($this->git->git(['rev-parse', 'HEAD'], $this->git->workspace));
    Queue::assertPushed(CreatePullRequestJob::class);
});

it('never pushes the default branch of the repository', function () {
    [, , $thread] = endedTurn(repositoryAttributes: ['default_branch' => 'main']);
    $this->git->git(['checkout', 'main'], $this->git->workspace);
    $this->git->branch = 'main';
    threadWithWorkspace($this, $thread);
    commitInWorkspace($this, 'change.txt');
    $remoteHead = $this->git->git(['rev-parse', 'refs/heads/main']);

    expect(app(EnsurePushedBranch::class)->pushPrimaryIfBehind($thread))->toBeFalse()
        ->and($this->git->git(['rev-parse', 'refs/heads/main']))->toBe($remoteHead);
});
