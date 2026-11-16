<?php

namespace App\Contracts;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * The shell and file operations the after-run helpers need from a sandbox:
 * git, artifact collection and attachment staging. A task sandbox implements
 * it by container name; a thread sandbox implements it for its one container.
 */
interface SandboxShell
{
    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult;

    public function pullFile(string $containerName, string $remotePath, string $localPath): void;

    public function pullDirectory(string $containerName, string $remotePath, string $localPath): void;

    public function pushFile(string $containerName, string $localPath, string $remotePath): void;

    public function fileExists(string $containerName, string $path): bool;
}
