<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\WipPreserver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ExtrasGit;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    $this->wipTempDir = sys_get_temp_dir() . '/yak-wip-' . uniqid();
    mkdir($this->wipTempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->wipTempDir);
    app()->instance(SandboxManager::class, $this->git);
});

afterEach(fn () => File::deleteDirectory($this->wipTempDir));

function wipThread(LocalGitSandboxManager $git, array $attributes = []): Thread
{
    return Thread::factory()->create([
        'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
        'sandbox_state' => ThreadSandboxState::Running,
        'runner_state' => RunnerState::None,
        'branch' => $git->branch,
        ...$attributes,
    ]);
}

test('uncommitted changes are committed and pushed to the thread branch', function () {
    $thread = wipThread($this->git);
    file_put_contents("{$this->git->workspace}/work.txt", "in progress\n");

    $result = app(WipPreserver::class)->preserve($thread);

    expect($result->committed)->toBeTrue()->and($result->pushed)->toBeTrue()
        ->and($this->git->git(['log', '-1', '--format=%s', $this->git->branch]))->toBe('WIP: Yak sandbox snapshot')
        ->and($this->git->git(['show', "{$this->git->branch}:work.txt"]))->toBe('in progress');
});

test('untracked files are included and ignored files are not', function () {
    $thread = wipThread($this->git);
    file_put_contents("{$this->git->workspace}/new.txt", 'new');
    file_put_contents("{$this->git->workspace}/ignored.log", 'noise');

    app(WipPreserver::class)->preserve($thread);

    $files = $this->git->git(['ls-tree', '--name-only', $this->git->branch]);
    expect($files)->toContain('new.txt')->not->toContain('ignored.log');
});

test('a clean tree with no unpushed commits does nothing', function () {
    $thread = wipThread($this->git);

    $result = app(WipPreserver::class)->preserve($thread);

    expect($result->committed)->toBeFalse()->and($result->pushed)->toBeFalse()->and($result->sha)->toBeNull()
        ->and($this->git->git(['branch', '--list', $this->git->branch]))->toBe('');
});

test('unpushed commits with a clean tree are pushed', function () {
    $thread = wipThread($this->git);
    file_put_contents("{$this->git->workspace}/local.txt", 'x');
    $this->git->git(['add', '-A'], $this->git->workspace);
    $this->git->git(['-c', 'user.name=Dev', '-c', 'user.email=dev@example.com', 'commit', '-m', 'Local commit'], $this->git->workspace);

    $result = app(WipPreserver::class)->preserve($thread);

    expect($result->committed)->toBeFalse()->and($result->pushed)->toBeTrue()
        ->and($this->git->git(['log', '-1', '--format=%s', $this->git->branch]))->toBe('Local commit');
});

test('teleport threads commit as the person with the co author trailer', function () {
    $thread = wipThread($this->git, ['git_author_name' => 'Ada Lovelace', 'git_author_email' => 'ada@example.com']);
    file_put_contents("{$this->git->workspace}/work.txt", 'x');

    app(WipPreserver::class)->preserve($thread);

    expect($this->git->git(['log', '-1', '--format=%an <%ae>', $this->git->branch]))->toBe('Ada Lovelace <ada@example.com>')
        ->and($this->git->git(['log', '-1', '--format=%B', $this->git->branch]))->toContain('Co-authored-by: Yak <yak@geocod.io>');
});

test('a teleport commit uses the teleport subject, the person and the Yak trailer', function () {
    $thread = wipThread($this->git, ['git_author_name' => 'Ada Lovelace', 'git_author_email' => 'ada@example.com']);
    file_put_contents("{$this->git->workspace}/work.txt", 'x');

    app(WipPreserver::class)->preserveForTeleport($thread);

    expect($this->git->git(['log', '-1', '--format=%an <%ae>', $this->git->branch]))->toBe('Ada Lovelace <ada@example.com>')
        ->and($this->git->git(['log', '-1', '--format=%s', $this->git->branch]))->toBe('WIP: teleport to local machine')
        ->and($this->git->git(['log', '-1', '--format=%B', $this->git->branch]))->toContain('Co-authored-by: Yak <yak@geocod.io>');
});

test('threads without a person commit as Yak', function () {
    $thread = wipThread($this->git);
    file_put_contents("{$this->git->workspace}/work.txt", 'x');

    app(WipPreserver::class)->preserve($thread);

    expect($this->git->git(['log', '-1', '--format=%an', $this->git->branch]))->toBe(config('yak.git_user_name'))
        ->and($this->git->git(['log', '-1', '--format=%B', $this->git->branch]))->not->toContain('Co-authored-by');
});

test('a diverged remote is rebased then pushed', function () {
    $thread = wipThread($this->git);
    $this->git->pushFromElsewhere('remote.txt', 'remote');
    file_put_contents("{$this->git->workspace}/work.txt", 'local');

    $result = app(WipPreserver::class)->preserve($thread);

    expect($result->pushed)->toBeTrue()
        ->and($this->git->git(['ls-tree', '--name-only', $this->git->branch]))->toContain('remote.txt')->toContain('work.txt');
});

test('a second push failure throws and does not report pushed', function () {
    $thread = wipThread($this->git);
    $this->git->rejectPushes();
    file_put_contents("{$this->git->workspace}/work.txt", 'x');

    expect(fn () => app(WipPreserver::class)->preserve($thread))->toThrow(SandboxCommandFailed::class);
    expect($thread->fresh()->wip_commit_sha)->toBeNull();
});

test('push errors never contain the remote url', function () {
    $thread = wipThread($this->git);
    $this->git->rejectPushes();
    file_put_contents("{$this->git->workspace}/work.txt", 'x');

    $message = '';

    try {
        app(WipPreserver::class)->preserve($thread);
    } catch (SandboxCommandFailed $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain('WIP push failed')->not->toContain($this->git->bare);
});

test('wip commit sha is recorded', function () {
    $thread = wipThread($this->git);
    file_put_contents("{$this->git->workspace}/work.txt", 'x');

    $result = app(WipPreserver::class)->preserve($thread);

    expect($thread->fresh()->wip_commit_sha)->toBe($result->sha)->toBe($this->git->git(['rev-parse', $this->git->branch]));
});

test('preserveAll still pushes the extra when the primary push fails, then rethrows', function () {
    $thread = wipThread($this->git);
    $bare = ExtrasGit::seedBare($this->wipTempDir, 'lib');
    $path = "{$this->git->workspace}/extras/lib";
    Process::run(['git', 'clone', $bare, $path])->throw();
    Process::path($path)->run(['git', 'checkout', '-b', $this->git->branch])->throw();
    ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib', 'branch' => $this->git->branch, 'sandbox_path' => $path]);
    file_put_contents("{$this->git->workspace}/work.txt", 'primary');
    file_put_contents("{$path}/extra.txt", 'extra');
    $this->git->rejectPushes();

    expect(fn () => app(WipPreserver::class)->preserveAll($thread))->toThrow(SandboxCommandFailed::class)
        ->and(trim(Process::path($bare)->run(['git', 'show', "{$this->git->branch}:extra.txt"])->output()))->toBe('extra');
});
