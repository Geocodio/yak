<?php

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ExtraRepositorySetup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\ExtrasGit;
use Tests\Support\ScriptRunningSandboxManager;

beforeEach(function () {
    $this->extrasDirectory = sys_get_temp_dir() . '/yak-extras-' . uniqid();
    mkdir($this->extrasDirectory, 0777, true);
});

afterEach(function () {
    File::deleteDirectory($this->extrasDirectory);
});

test('puts a changes repo on the thread branch and a context repo on its default branch', function () {
    $directory = $this->extrasDirectory;
    $manager = ScriptRunningSandboxManager::withRepository($directory);
    $manager->remotes = [
        'https://github.com/acme/lib.git' => ExtrasGit::seedBare($directory, 'lib'),
        'https://github.com/acme/docs.git' => ExtrasGit::seedBare($directory, 'docs'),
    ];
    app()->instance(SandboxManager::class, $manager);

    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'repo' => 'acme/app', 'branch' => 'yak/thread-branch']);
    $lib = ThreadRepository::factory()->changes()->create(['thread_id' => $thread->id, 'repo' => 'acme/lib', 'sandbox_path' => "{$directory}/extras/lib", 'setup_status' => RepositorySetupStatus::Pending]);
    $docs = ThreadRepository::factory()->context()->create(['thread_id' => $thread->id, 'repo' => 'acme/docs', 'sandbox_path' => "{$directory}/extras/docs", 'setup_status' => RepositorySetupStatus::Pending]);

    $results = app(ExtraRepositorySetup::class)->setUpAll($thread, collect([$lib, $docs]));

    expect($results[0]->status)->toBe(RepositorySetupStatus::Ready)
        ->and($results[1]->status)->toBe(RepositorySetupStatus::Ready)
        ->and(trim(Process::path("{$directory}/extras/lib")->run(['git', 'branch', '--show-current'])->output()))->toBe('yak/thread-branch')
        ->and(trim(Process::path("{$directory}/extras/docs")->run(['git', 'branch', '--show-current'])->output()))->toBe('main')
        ->and($lib->fresh()->branch)->toBe('yak/thread-branch')
        ->and($docs->fresh()->role)->toBe(RepositoryRole::Context)
        ->and($docs->fresh()->branch)->toBeNull();
});
