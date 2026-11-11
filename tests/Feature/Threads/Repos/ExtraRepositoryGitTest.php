<?php

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ExtraRepositorySetup;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Support\LocalGitSandboxManager;

/** Runs stdin scripts for real and points github.com clone URLs at local bare repositories. */
class ScriptRunningSandboxManager extends LocalGitSandboxManager
{
    /** @var array<string, string> */
    public array $remotes = [];

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        if ($stdin === null || $command !== ['bash', '-s']) {
            return parent::exec($thread, $command, $stdin, $env);
        }

        FakeCall::record($this, $thread, $command, $stdin, $env);

        $config = ['GIT_CONFIG_COUNT' => (string) count($this->remotes)];
        $index = 0;
        foreach ($this->remotes as $url => $path) {
            $config["GIT_CONFIG_KEY_{$index}"] = "url.{$path}.insteadOf";
            $config["GIT_CONFIG_VALUE_{$index}"] = $url;
            $index++;
        }

        return Process::env([...$config, 'GIT_TERMINAL_PROMPT' => '0'])->input($stdin)->run($command);
    }
}

class FakeCall
{
    public static function record(ScriptRunningSandboxManager $manager, Thread $thread, array $command, string $stdin, array $env): void
    {
        $manager->calls[] = ['operation' => 'exec', 'threadId' => $thread->id, 'arguments' => ['command' => $command, 'stdin' => $stdin, 'env' => $env]];
    }
}

function seedBare(string $directory, string $name): string
{
    $bare = "{$directory}/{$name}.git";
    $seed = "{$directory}/{$name}-seed";
    mkdir($seed, 0777, true);
    $identity = ['-c', 'user.name=Seed', '-c', 'user.email=seed@example.com'];

    Process::path($directory)->run(['git', 'init', '--bare', '-b', 'main', $bare])->throw();
    Process::path($seed)->run(['git', 'init', '-b', 'main'])->throw();
    file_put_contents("{$seed}/README.md", "{$name}\n");
    Process::path($seed)->run(['git', 'add', '-A'])->throw();
    Process::path($seed)->run(['git', ...$identity, 'commit', '-m', 'Initial commit'])->throw();
    Process::path($seed)->run(['git', 'push', $bare, 'main'])->throw();

    return $bare;
}

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
        'https://github.com/acme/lib.git' => seedBare($directory, 'lib'),
        'https://github.com/acme/docs.git' => seedBare($directory, 'docs'),
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
