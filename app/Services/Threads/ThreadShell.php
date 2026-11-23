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
    /** Raw bytes per sandbox read; the base64 reply is a third larger. */
    private const CHUNK_BYTES = 3 * 1024 * 1024;

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

    /**
     * Only a regular, non-symlink file within the configured byte limit is pulled, and the
     * read stops at the stated size, so a device, FIFO or growing file cannot fill the host disk.
     */
    public function pullFile(string $containerName, string $remotePath, string $localPath): void
    {
        $size = $this->regularFileSize($containerName, $remotePath);
        $limit = (int) config('yak.threads.artifact_pull_max_bytes');

        if ($size > $limit) {
            throw new RuntimeException("Refusing to pull {$remotePath}: {$size} bytes exceeds the {$limit} byte limit.");
        }

        $handle = fopen($localPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not write {$localPath}.");
        }

        try {
            $written = 0;

            for ($chunkIndex = 0; $chunkIndex < (int) ceil($size / self::CHUNK_BYTES); $chunkIndex++) {
                $chunk = $this->readChunk($containerName, $remotePath, $chunkIndex);
                $written += strlen($chunk);

                if ($written > $size) {
                    throw new RuntimeException("Refusing to pull {$remotePath}: it returned more than its stated {$size} bytes.");
                }

                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Pulls the directory into `$localPath/<basename>`, like `incus file pull -r`.
     * The directory is archived inside the sandbox and the archive is pulled in
     * chunks, so memory stays bounded however large the directory is.
     */
    public function pullDirectory(string $containerName, string $remotePath, string $localPath): void
    {
        File::ensureDirectoryExists($localPath);

        $remoteArchive = '/tmp/yak-pull-' . bin2hex(random_bytes(8)) . '.tar';
        $archive = $this->run($containerName, sprintf(
            'tar -cf %s -C %s %s',
            escapeshellarg($remoteArchive),
            escapeshellarg(dirname($remotePath)),
            escapeshellarg(basename($remotePath)),
        ));

        if ($archive->failed()) {
            throw new RuntimeException('Could not read from the sandbox: ' . trim($archive->errorOutput()));
        }

        $localArchive = tempnam(sys_get_temp_dir(), 'thread-pull');

        try {
            $this->pullFile($containerName, $remoteArchive, $localArchive);
            $extracted = Process::run(['tar', '-xf', $localArchive, '-C', $localPath]);

            if ($extracted->failed()) {
                throw new RuntimeException("Could not unpack {$remotePath}: " . trim($extracted->errorOutput()));
            }
        } finally {
            @unlink($localArchive);
            $this->run($containerName, 'rm -f ' . escapeshellarg($remoteArchive));
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

    /** Returns the byte size of a regular, non-symlink sandbox file and refuses anything else. */
    private function regularFileSize(string $containerName, string $remotePath): int
    {
        $path = escapeshellarg($remotePath);
        $result = $this->run($containerName, "if [ -f {$path} ] && [ ! -L {$path} ]; then stat -c %s {$path}; else echo -1; fi");
        $size = trim($result->output());

        if ($result->failed() || ! ctype_digit($size)) {
            throw new RuntimeException("Refusing to pull {$remotePath}: it is not a regular file.");
        }

        return (int) $size;
    }

    /** Reads one CHUNK_BYTES slice of a sandbox file, so no single read holds the whole file. */
    private function readChunk(string $containerName, string $remotePath, int $chunkIndex): string
    {
        $result = $this->run($containerName, sprintf(
            'dd if=%s bs=%d skip=%d count=1 status=none | base64 -w0',
            escapeshellarg($remotePath),
            self::CHUNK_BYTES,
            $chunkIndex,
        ));

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
