<?php

namespace App\Services\Threads;

use App\Contracts\SandboxShell;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * SandboxShell over a thread's sandbox. Every operation goes through
 * SandboxManager::exec in the workspace, so the container name argument is
 * ignored. Commands run as the sandbox user, and the timeout and output
 * callback of the task-sandbox shell do not apply.
 */
class ThreadShell implements SandboxShell
{
    public function __construct(private readonly Thread $thread, private readonly SandboxManager $sandboxes) {}

    public static function for(Thread $thread): SandboxShell
    {
        return app()->make(self::class, ['thread' => $thread, 'sandboxes' => app(SandboxManager::class)]);
    }

    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
    {
        return $this->sandboxes->exec($this->thread, ['bash', '-lc', $command], $input);
    }

    public function fileExists(string $containerName, string $path): bool
    {
        return $this->run($containerName, 'test -e ' . escapeshellarg($path))->exitCode() === 0;
    }

    public function pullFile(string $containerName, string $remotePath, string $localPath): void
    {
        File::put($localPath, $this->readBase64($containerName, 'cat ' . escapeshellarg($remotePath)));
    }

    /** Pulls the directory into `$localPath/<basename>`, like `incus file pull -r`. */
    public function pullDirectory(string $containerName, string $remotePath, string $localPath): void
    {
        File::ensureDirectoryExists($localPath);

        $archive = $this->readBase64($containerName, sprintf('tar -cf - -C %s %s', escapeshellarg(dirname($remotePath)), escapeshellarg(basename($remotePath))));
        $extracted = Process::input($archive)->run(['tar', '-xf', '-', '-C', $localPath]);

        if ($extracted->failed()) {
            throw new RuntimeException("Could not unpack {$remotePath}: " . trim($extracted->errorOutput()));
        }
    }

    public function pushFile(string $containerName, string $localPath, string $remotePath): void
    {
        $result = $this->run(
            $containerName,
            sprintf('mkdir -p %s && base64 -d > %s', escapeshellarg(dirname($remotePath)), escapeshellarg($remotePath)),
            input: base64_encode(File::get($localPath)),
        );

        if ($result->failed()) {
            throw new RuntimeException("Could not push {$remotePath}: " . trim($result->errorOutput()));
        }
    }

    private function readBase64(string $containerName, string $producer): string
    {
        $result = $this->run($containerName, "{$producer} | base64 -w0");

        if ($result->failed()) {
            throw new RuntimeException('Could not read from the sandbox: ' . trim($result->errorOutput()));
        }

        $decoded = base64_decode(trim($result->output()), true);

        if ($decoded === false) {
            throw new RuntimeException('The sandbox returned unreadable file content.');
        }

        return $decoded;
    }
}
