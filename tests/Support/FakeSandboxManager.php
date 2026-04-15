<?php

namespace Tests\Support;

use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Fake sandbox manager for testing.
 *
 * Returns predictable container names and no-ops all Incus operations
 * while allowing Process::fake() to handle any commands that run.
 */
class FakeSandboxManager extends IncusSandboxManager
{
    /** @var array<int, string> */
    public array $createdContainers = [];

    /** @var array<int, string> */
    public array $destroyedContainers = [];

    /** @var array<int, string> */
    public array $snapshots = [];

    /** @var array<int, string> */
    public array $promotedTemplates = [];

    private bool $hasSnapshotResult = false;

    public function setHasSnapshot(bool $value): self
    {
        $this->hasSnapshotResult = $value;

        return $this;
    }

    public function create(YakTask $task, Repository $repository): string
    {
        $name = $this->containerName($task);
        $this->createdContainers[] = $name;

        return $name;
    }

    public function run(string $containerName, string $command, ?int $timeout = null): ProcessResult
    {
        return Process::result('');
    }

    /**
     * @return array{resource, array<int, resource>}
     */
    public function streamExec(string $containerName, string $command): array
    {
        // Create a pair of pipes that the test can use
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open('echo ""', $descriptors, $pipes);

        return [$process, $pipes];
    }

    public function pullFile(string $containerName, string $remotePath, string $localPath): void
    {
        // No-op in tests
    }

    public function pullDirectory(string $containerName, string $remotePath, string $localPath): void
    {
        // No-op in tests
    }

    public function pushFile(string $containerName, string $localPath, string $remotePath): void
    {
        // No-op in tests
    }

    public function fileExists(string $containerName, string $path): bool
    {
        return false;
    }

    public function snapshot(string $containerName, string $snapshotName): void
    {
        $this->snapshots[] = "{$containerName}/{$snapshotName}";
    }

    public function promoteToTemplate(string $containerName, Repository $repository): string
    {
        $templateName = $this->templateName($repository);
        $this->promotedTemplates[] = $templateName;

        return "{$templateName}/ready";
    }

    public function destroy(string $containerName): void
    {
        $this->destroyedContainers[] = $containerName;
    }

    public function hasSnapshot(Repository $repository): bool
    {
        return $this->hasSnapshotResult;
    }

    public function cleanupStale(): int
    {
        return 0;
    }
}
