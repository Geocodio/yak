<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\Support\ExtrasGit;
use Tests\Support\FakeSessionFileSync;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    Sleep::fake();
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->extrasDirectory = sys_get_temp_dir() . '/yak-extras-survive-' . uniqid();
    mkdir($this->extrasDirectory, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->extrasDirectory);
    app()->instance(SandboxManager::class, $this->git);
});

afterEach(fn () => File::deleteDirectory($this->extrasDirectory));

/** Clones a second bare repository into the sandbox as an extra and dirties it. */
function dirtyExtra(string $directory, string $branch): array
{
    $bare = ExtrasGit::seedBare($directory, 'lib');
    $path = "{$directory}/workspace/extras/lib";
    Process::run(['git', 'clone', $bare, $path])->throw();
    Process::path($path)->run(['git', 'checkout', '-b', $branch])->throw();
    file_put_contents("{$path}/README.md", "extra edit\n");
    file_put_contents("{$path}/extra-new.txt", "extra untracked\n");

    return [$bare, $path];
}

function extraThread(LocalGitSandboxManager $git, string $path, string $role): Thread
{
    $thread = Thread::factory()->stopped()->create([
        'repo' => 'acme/app',
        'branch' => $git->branch,
        'runner_state' => RunnerState::None,
        'last_activity_at' => now()->subHours(73),
    ]);
    ThreadRepository::factory()->{$role}()->create([
        'thread_id' => $thread->id,
        'repo' => 'acme/lib',
        'branch' => $role === 'changes' ? $git->branch : null,
        'sandbox_path' => $path,
    ]);

    return $thread;
}

test('72 hour destroy commits and pushes uncommitted and untracked work in a changes extra', function () {
    [$bare, $path] = dirtyExtra($this->extrasDirectory, $this->git->branch);
    $thread = extraThread($this->git, $path, 'changes');

    $this->artisan('yak:thread-sandboxes')->assertSuccessful();

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and(trim(Process::path($bare)->run(['git', 'show', "{$this->git->branch}:README.md"])->output()))->toBe('extra edit')
        ->and(trim(Process::path($bare)->run(['git', 'show', "{$this->git->branch}:extra-new.txt"])->output()))->toBe('extra untracked');
});

test('a rejected extra push blocks the destroy', function () {
    [$bare, $path] = dirtyExtra($this->extrasDirectory, $this->git->branch);
    $thread = extraThread($this->git, $path, 'changes');
    $hook = "{$bare}/hooks/pre-receive";
    file_put_contents($hook, "#!/bin/sh\necho rejected >&2\nexit 1\n");
    chmod($hook, 0755);

    $this->artisan('yak:thread-sandboxes')->assertSuccessful();

    expect($thread->fresh()->sandbox_state)->not->toBe(ThreadSandboxState::Destroyed);
});

test('a context extra is left alone', function () {
    [$bare, $path] = dirtyExtra($this->extrasDirectory, $this->git->branch);
    $thread = extraThread($this->git, $path, 'context');

    $this->artisan('yak:thread-sandboxes')->assertSuccessful();

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and(trim(Process::path($bare)->run(['git', 'branch', '--list', $this->git->branch])->output()))->toBe('');
});

test('eviction stop preserves the extra', function () {
    [$bare, $path] = dirtyExtra($this->extrasDirectory, $this->git->branch);
    $thread = extraThread($this->git, $path, 'changes');
    $thread->update(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-x']);

    app(ThreadSandbox::class)->stop($thread->fresh(), true);

    expect(trim(Process::path($bare)->run(['git', 'show', "{$this->git->branch}:extra-new.txt"])->output()))->toBe('extra untracked');
});
