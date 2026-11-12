<?php

use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ExtraRepositorySetup;
use App\Services\Threads\Repos\ThreadRepositoryBranches;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ExtrasGit;
use Tests\Support\ScriptRunningSandboxManager;

beforeEach(function () {
    $this->extrasDirectory = sys_get_temp_dir() . '/yak-extra-branch-' . uniqid();
    mkdir($this->extrasDirectory, 0777, true);

    $directory = $this->extrasDirectory;
    $manager = ScriptRunningSandboxManager::withRepository($directory);
    $manager->remotes = ['https://github.com/acme/lib.git' => ExtrasGit::seedBare($directory, 'lib')];
    app()->instance(SandboxManager::class, $manager);

    $this->thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'repo' => 'acme/app', 'branch' => 'yak/t']);
});

afterEach(function () {
    File::deleteDirectory($this->extrasDirectory);
});

function branchOf(string $path): string
{
    return trim(Process::path($path)->run(['git', 'branch', '--show-current'])->output());
}

test('assigns the thread branch to every changes row without one', function () {
    $thread = $this->thread;
    $first = ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib', 'branch' => null]);
    $context = ThreadRepository::factory()->context()->create(['thread_id' => $thread->id, 'repo' => 'acme/docs', 'branch' => null]);
    $own = ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/other', 'branch' => 'yak/own']);

    app(ThreadRepositoryBranches::class)->assign($thread);

    expect($first->fresh()->branch)->toBe('yak/t')
        ->and($context->fresh()->branch)->toBeNull()
        ->and($own->fresh()->branch)->toBe('yak/own');
});

test('restore keeps a pushed extra branch', function () {
    $directory = $this->extrasDirectory;
    $row = ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/lib', 'branch' => null, 'sandbox_path' => "{$directory}/extras/lib", 'setup_status' => RepositorySetupStatus::Pending]);

    app(ExtraRepositorySetup::class)->setUpAll($this->thread, collect([$row]));

    $path = "{$directory}/extras/lib";
    file_put_contents("{$path}/work.txt", "pushed\n");
    Process::path($path)->run(['git', 'add', '-A'])->throw();
    Process::path($path)->run(['git', '-c', 'user.name=T', '-c', 'user.email=t@example.com', 'commit', '-m', 'work'])->throw();
    Process::path($path)->run(['git', 'push', $directory . '/lib.git', 'HEAD:refs/heads/yak/t'])->throw();
    File::deleteDirectory($path);

    app(ExtraRepositorySetup::class)->setUpAll($this->thread, collect([$row->fresh()]));

    expect(branchOf($path))->toBe('yak/t')
        ->and(file_exists("{$path}/work.txt"))->toBeTrue();
});

test('setup never overwrites an existing row branch', function () {
    $directory = $this->extrasDirectory;
    $row = ThreadRepository::factory()->changes()->create(['thread_id' => $this->thread->id, 'repo' => 'acme/lib', 'branch' => 'yak/own', 'sandbox_path' => "{$directory}/extras/lib", 'setup_status' => RepositorySetupStatus::Pending]);

    app(ExtraRepositorySetup::class)->setUpAll($this->thread, collect([$row]));

    expect($row->fresh()->branch)->toBe('yak/own')
        ->and(branchOf("{$directory}/extras/lib"))->toBe('yak/own');
});
