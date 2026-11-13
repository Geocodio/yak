<?php

namespace Tests\Support;

use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Fake sandbox whose exec runs real commands in a clone of a local bare
 * repository, so git behaviour is exercised for real.
 */
class LocalGitSandboxManager extends FakeSandboxManager
{
    public string $bare;

    public string $workspace;

    public string $branch = 'yak/thread-branch';

    public static function withRepository(string $tempDir): static
    {
        $manager = new static; // @phpstan-ignore new.static
        $manager->bare = "{$tempDir}/origin.git";
        $manager->workspace = "{$tempDir}/workspace";
        $seed = "{$tempDir}/seed";

        mkdir($seed, 0777, true);
        $manager->run($tempDir, ['git', 'init', '--bare', '-b', 'main', $manager->bare]);
        $manager->run($seed, ['git', 'init', '-b', 'main']);
        file_put_contents("{$seed}/README.md", "seed\n");
        file_put_contents("{$seed}/.gitignore", "ignored.log\n");
        $manager->run($seed, ['git', 'add', '-A']);
        $manager->run($seed, [...self::IDENTITY, 'commit', '-m', 'Initial commit']);
        $manager->run($seed, ['git', 'remote', 'add', 'origin', $manager->bare]);
        $manager->run($seed, ['git', 'push', 'origin', 'main']);
        $manager->run($tempDir, ['git', 'clone', $manager->bare, $manager->workspace]);
        $manager->run($manager->workspace, ['git', 'checkout', '-b', $manager->branch]);

        return $manager;
    }

    private const IDENTITY = ['git', '-c', 'user.name=Seed', '-c', 'user.email=seed@example.com'];

    /** Rejects every push to the bare repository. */
    public function rejectPushes(): void
    {
        $hook = "{$this->bare}/hooks/pre-receive";
        file_put_contents($hook, "#!/bin/sh\necho rejected >&2\nexit 1\n");
        chmod($hook, 0755);
    }

    /** Checks out a new branch at origin/main in the workspace; nothing is pushed. */
    public function useBranchFromDefault(string $branch): void
    {
        $this->run($this->workspace, ['git', 'checkout', '-B', $branch, 'origin/main']);
    }

    /** Pushes a commit to the thread branch from a second clone. */
    public function pushFromElsewhere(string $file, string $contents): void
    {
        $other = dirname($this->bare) . '/other-' . uniqid();
        $this->run(dirname($this->bare), ['git', 'clone', $this->bare, $other]);
        $this->run($other, ['git', 'checkout', '-B', $this->branch, 'origin/main']);
        file_put_contents("{$other}/{$file}", $contents);
        $this->run($other, ['git', 'add', '-A']);
        $this->run($other, [...self::IDENTITY, 'commit', '-m', 'Remote change']);
        $this->run($other, ['git', 'push', 'origin', "HEAD:refs/heads/{$this->branch}"]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function git(array $arguments, ?string $path = null): string
    {
        return trim($this->run($path ?? $this->bare, ['git', ...$arguments])->output());
    }

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        parent::exec($thread, $command, $stdin, $env);

        // Commands fed through stdin write outside the workspace (the environment note); they are recorded, not run.
        if ($stdin !== null) {
            return Process::result();
        }

        // The real sandbox user has passwordless sudo; the test machine does not.
        if (($command[2] ?? null) === SandboxManager::LINK_LOCAL_PATH_SCRIPT) {
            $command[2] = str_replace('sudo ', '', $command[2]);
        }

        return $this->run($this->workspace, $command, $stdin, $env);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    private function run(string $path, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        $process = Process::path($path)->env($env);

        if ($stdin !== null) {
            $process = $process->input($stdin);
        }

        return $process->run($command);
    }
}
