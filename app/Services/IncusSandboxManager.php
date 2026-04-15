<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Manages Incus system containers for sandboxed task execution.
 *
 * Each task gets its own Incus container cloned from a per-repo snapshot.
 * Containers have their own Docker daemon, network namespace, and filesystem
 * via ZFS copy-on-write — providing full isolation from the yak host.
 */
class IncusSandboxManager
{
    /**
     * Create a sandbox container for a task, cloned from the repo's snapshot.
     *
     * If the repo has a sandbox snapshot, clones from it (instant CoW).
     * Otherwise, clones from the base template.
     */
    public function create(YakTask $task, Repository $repository): string
    {
        $containerName = $this->containerName($task);

        // Self-heal: reclaim any stale container left behind by a prior
        // attempt. Without this, a retry after a worker hard-kill hits
        // `incus copy` with an "already exists" error.
        if ($this->containerExists($containerName)) {
            Log::channel('yak')->warning('Reclaiming stale sandbox before create', [
                'container' => $containerName,
                'task_id' => $task->id,
            ]);
            $this->destroy($containerName);
        }

        $source = $this->resolveSource($repository);

        Log::channel('yak')->info('Creating sandbox container', [
            'container' => $containerName,
            'source' => $source,
            'task_id' => $task->id,
        ]);

        // Clone from snapshot (instant with ZFS CoW)
        $this->exec("incus copy {$source} {$containerName}");

        // Apply resource limits
        $this->configureResources($containerName);

        // Start the container
        $this->exec("incus start {$containerName}");

        // Wait for the container to be ready (systemd + Docker daemon)
        $this->waitForReady($containerName);

        // Push Claude config into the container
        $this->pushClaudeConfig($containerName);

        // Push MCP config if configured
        $this->pushMcpConfig($containerName);

        // Normalize /workspace ownership so every git operation — whether
        // run by the agent or by job code — sees a consistently yak-owned
        // tree. Legacy templates were built with `git clone` as root, which
        // left `.git` root-owned and tripped git's dubious-ownership check.
        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');
        $this->run(
            $containerName,
            'chown -R yak:yak ' . escapeshellarg($workspacePath),
            timeout: 30,
            asRoot: true,
        );

        Log::channel('yak')->info('Sandbox container ready', [
            'container' => $containerName,
            'task_id' => $task->id,
        ]);

        return $containerName;
    }

    /**
     * Execute a command inside a sandbox container.
     *
     * Commands run as the `yak` user by default so file ownership stays
     * consistent with the agent (which also runs as yak). Set `asRoot: true`
     * for the rare privileged operations (chown of pushed files, etc.).
     *
     * Returns the raw process result for callers that need stdout/stderr.
     */
    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false): ProcessResult
    {
        $cmd = $this->buildExecCommand($containerName, $command, $asRoot);

        $process = Process::timeout($timeout ?? 600);

        return $process->run($cmd);
    }

    /**
     * Execute a command inside a sandbox using proc_open for streaming.
     *
     * Defaults to running as the `yak` user (see `run()` for rationale).
     *
     * Returns the proc_open resource and pipes for line-by-line streaming.
     *
     * @return array{resource, array<int, resource>}
     */
    public function streamExec(string $containerName, string $command, bool $asRoot = false): array
    {
        $cmd = $this->buildExecCommand($containerName, $command, $asRoot);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException("Failed to start process in sandbox {$containerName}");
        }

        return [$process, $pipes];
    }

    /**
     * Pull a file from a sandbox container to the host.
     *
     * The remote path is part of an incus argument like `task-1/foo` so it
     * must be appended (after a slash) to the container name in a single
     * escapeshellarg() call.
     */
    public function pullFile(string $containerName, string $remotePath, string $localPath): void
    {
        $this->exec(sprintf(
            'incus file pull %s %s',
            escapeshellarg($containerName . $remotePath),
            escapeshellarg($localPath),
        ));
    }

    /**
     * Pull a directory recursively from a sandbox container.
     */
    public function pullDirectory(string $containerName, string $remotePath, string $localPath): void
    {
        if (! is_dir($localPath)) {
            mkdir($localPath, 0755, true);
        }

        $this->exec(sprintf(
            'incus file pull -r %s %s',
            escapeshellarg($containerName . $remotePath),
            escapeshellarg($localPath),
        ));
    }

    /**
     * Push a file into a sandbox container.
     */
    public function pushFile(string $containerName, string $localPath, string $remotePath): void
    {
        $this->exec(sprintf(
            'incus file push %s %s',
            escapeshellarg($localPath),
            escapeshellarg($containerName . $remotePath),
        ));
    }

    /**
     * Check if a file exists inside a sandbox container.
     */
    public function fileExists(string $containerName, string $path): bool
    {
        $result = $this->run($containerName, 'test -e ' . escapeshellarg($path), timeout: 10);

        return $result->exitCode() === 0;
    }

    /**
     * Create a snapshot of a container for future task cloning.
     *
     * Called after a successful setup task to preserve the prepared state.
     */
    public function snapshot(string $containerName, string $snapshotName): void
    {
        Log::channel('yak')->info('Creating sandbox snapshot', [
            'container' => $containerName,
            'snapshot' => $snapshotName,
        ]);

        // Stop the container before snapshotting for a clean state
        $this->exec("incus stop {$containerName}");

        // Delete existing snapshot if present (idempotent re-snapshot)
        Process::run("incus snapshot delete {$containerName} {$snapshotName} 2>/dev/null");

        $this->exec("incus snapshot create {$containerName} {$snapshotName}");

        Log::channel('yak')->info('Sandbox snapshot created', [
            'container' => $containerName,
            'snapshot' => $snapshotName,
        ]);
    }

    /**
     * Promote a task container to a repo template with a snapshot.
     *
     * After setup completes, this converts the task's sandbox into the
     * repo's reusable template. Future tasks clone from this snapshot.
     */
    public function promoteToTemplate(string $containerName, Repository $repository): string
    {
        $templateName = $this->templateName($repository);
        $snapshotName = (string) config('yak.sandbox.snapshot_name', 'ready');

        // Delete old template if it exists
        Process::run("incus delete {$templateName} --force 2>/dev/null");

        // Stop the task container
        $this->exec("incus stop {$containerName}");

        // Copy task container as the new template
        $this->exec("incus copy {$containerName} {$templateName}");

        // Snapshot the template
        $this->exec("incus snapshot create {$templateName} {$snapshotName}");

        // Stamp the repo with the yak-base version the template inherits
        // from. A later bump to config('yak.sandbox.base_version') will
        // trigger ensureTemplateVersionCurrent() to invalidate this template.
        $repository->update([
            'sandbox_base_version' => (int) config('yak.sandbox.base_version', 1),
        ]);

        Log::channel('yak')->info('Promoted sandbox to repo template', [
            'source' => $containerName,
            'template' => $templateName,
            'snapshot' => $snapshotName,
            'base_version' => $repository->sandbox_base_version,
        ]);

        return "{$templateName}/{$snapshotName}";
    }

    /**
     * Destroy a sandbox container and free its resources.
     */
    public function destroy(string $containerName): void
    {
        Log::channel('yak')->info('Destroying sandbox container', [
            'container' => $containerName,
        ]);

        // Force stop + delete in one shot (ignore errors for already-stopped containers)
        Process::run("incus delete {$containerName} --force 2>/dev/null");
    }

    /**
     * Check if a repo has a sandbox snapshot ready for cloning.
     */
    public function hasSnapshot(Repository $repository): bool
    {
        $templateName = $this->templateName($repository);
        $snapshotName = (string) config('yak.sandbox.snapshot_name', 'ready');

        $result = Process::run("incus snapshot list {$templateName} --format csv 2>/dev/null");

        if ($result->exitCode() !== 0) {
            return false;
        }

        return str_contains($result->output(), $snapshotName);
    }

    /**
     * Generate the container name for a task.
     */
    public function containerName(YakTask $task): string
    {
        // Incus names: lowercase alphanumeric and hyphens, max 63 chars
        $sanitized = (string) preg_replace('/[^a-z0-9-]/', '-', strtolower("task-{$task->id}"));
        $sanitized = (string) preg_replace('/-{2,}/', '-', $sanitized);

        return trim($sanitized, '-');
    }

    /**
     * Generate the template container name for a repository.
     */
    public function templateName(Repository $repository): string
    {
        $sanitized = (string) preg_replace('/[^a-z0-9-]/', '-', strtolower("yak-tpl-{$repository->slug}"));
        $sanitized = (string) preg_replace('/-{2,}/', '-', $sanitized);

        return trim($sanitized, '-');
    }

    /**
     * Delete leftover task sandbox containers.
     *
     * Covers two cases:
     *  - STOPPED task-* containers (normal leftovers from completed jobs)
     *  - RUNNING task-* containers whose YakTask is in a terminal state
     *    (orphans from worker hard-kills on timeout — the `finally` block
     *    never got a chance to destroy the container)
     */
    public function cleanupStale(): int
    {
        $result = Process::run('incus list --format csv -c n,s 2>/dev/null');

        if ($result->exitCode() !== 0) {
            return 0;
        }

        $deleted = 0;

        foreach (explode("\n", trim($result->output())) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode(',', $line);
            $name = trim($parts[0]);
            $status = trim($parts[1] ?? '');

            if (! str_starts_with($name, 'task-')) {
                continue;
            }

            if ($status === 'STOPPED') {
                Process::run("incus delete {$name} --force");
                $deleted++;

                continue;
            }

            if ($status === 'RUNNING' && $this->isOrphaned($name)) {
                Log::channel('yak')->warning('Cleaning up orphaned sandbox', [
                    'container' => $name,
                ]);
                Process::run("incus delete {$name} --force");
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Check if a container with the given name exists in Incus.
     */
    public function containerExists(string $containerName): bool
    {
        $result = Process::run('incus info ' . escapeshellarg($containerName));

        return $result->exitCode() === 0;
    }

    /**
     * True when the repo's stored sandbox_base_version matches the current
     * config value.
     *
     * Repos without a template (no sandbox_snapshot) return true so they
     * pass through to the "not set up yet" path in EnsureRepoReady. Repos
     * that DO have a template but a null version predate the versioning
     * system, so they are treated as drifted and re-provisioned on the
     * next task run.
     */
    public function isTemplateUpToDate(Repository $repository): bool
    {
        if (empty($repository->sandbox_snapshot)) {
            return true;
        }

        return (int) $repository->sandbox_base_version === (int) config('yak.sandbox.base_version', 1);
    }

    /**
     * Destroy the repo template and reset the repository's sandbox state
     * so the next SetupYakJob can rebuild cleanly from yak-base. Used by
     * the EnsureRepoReady middleware when it detects a base_version drift.
     */
    public function invalidateTemplate(Repository $repository): void
    {
        $templateName = $this->templateName($repository);

        Log::channel('yak')->warning('Invalidating repo template for reprovisioning', [
            'repository' => $repository->slug,
            'template' => $templateName,
            'stored_version' => $repository->sandbox_base_version,
            'current_version' => (int) config('yak.sandbox.base_version', 1),
        ]);

        Process::run("incus delete {$templateName} --force 2>/dev/null");

        $repository->update([
            'sandbox_snapshot' => null,
            'sandbox_base_version' => null,
            'setup_status' => 'pending',
        ]);
    }

    /**
     * True when a task-* container's YakTask no longer exists or has
     * reached a state where no sandbox should legitimately be running.
     */
    private function isOrphaned(string $containerName): bool
    {
        if (! preg_match('/^task-(\d+)$/', $containerName, $matches)) {
            return false;
        }

        $task = YakTask::find((int) $matches[1]);

        if ($task === null) {
            return true;
        }

        /** @var TaskStatus $status */
        $status = $task->status;

        return in_array($status, [
            TaskStatus::Success,
            TaskStatus::Failed,
            TaskStatus::Expired,
        ], true);
    }

    /**
     * Resolve the source template/snapshot to clone from for a repository.
     */
    private function resolveSource(Repository $repository): string
    {
        $templateName = $this->templateName($repository);
        $snapshotName = (string) config('yak.sandbox.snapshot_name', 'ready');

        // Prefer repo-specific template with snapshot
        if ($this->hasSnapshot($repository)) {
            return "{$templateName}/{$snapshotName}";
        }

        // Fall back to base template
        $baseTemplate = (string) config('yak.sandbox.base_template', 'yak-base');
        $baseResult = Process::run("incus snapshot list {$baseTemplate} --format csv 2>/dev/null");

        if ($baseResult->exitCode() === 0 && str_contains($baseResult->output(), $snapshotName)) {
            return "{$baseTemplate}/{$snapshotName}";
        }

        // Last resort: copy from the base template directly (no snapshot)
        return $baseTemplate;
    }

    private function configureResources(string $containerName): void
    {
        $cpu = (int) config('yak.sandbox.cpu_limit', 4);
        $memory = (string) config('yak.sandbox.memory_limit', '8GB');
        $disk = (string) config('yak.sandbox.disk_limit', '30GB');

        $this->exec("incus config set {$containerName} limits.cpu={$cpu} limits.memory={$memory}");
        Process::run("incus config device set {$containerName} root size={$disk} 2>/dev/null");
    }

    private function waitForReady(string $containerName, int $maxWaitSeconds = 60): void
    {
        $start = time();

        while (time() - $start < $maxWaitSeconds) {
            $result = Process::run(
                "incus exec {$containerName} -- systemctl is-system-running 2>/dev/null",
            );

            $status = trim($result->output());

            if ($status === 'running' || $status === 'degraded') {
                // Also check Docker is ready
                $docker = Process::run(
                    "incus exec {$containerName} -- docker info 2>/dev/null",
                );

                if ($docker->exitCode() === 0) {
                    return;
                }
            }

            usleep(500_000); // 500ms
        }

        throw new RuntimeException("Sandbox {$containerName} did not become ready within {$maxWaitSeconds}s");
    }

    private function pushClaudeConfig(string $containerName): void
    {
        $claudeConfigSource = (string) config('yak.sandbox.claude_config_source', '/home/yak/.claude');

        if (! is_dir($claudeConfigSource)) {
            return;
        }

        // Push the entire claude config directory
        Process::run(sprintf(
            'incus file push -r %s %s/home/yak/',
            escapeshellarg($claudeConfigSource),
            escapeshellarg($containerName),
        ));

        // Also push .claude.json if it exists on the host
        $claudeJson = dirname($claudeConfigSource) . '/.claude.json';
        if (file_exists($claudeJson)) {
            Process::run(sprintf(
                'incus file push %s %s/home/yak/.claude.json',
                escapeshellarg($claudeJson),
                escapeshellarg($containerName),
            ));
        }

        // Fix ownership inside container. `incus file push` lands files as
        // root; chown must run as root to change ownership to yak.
        $this->run($containerName, 'chown -R yak:yak /home/yak/.claude /home/yak/.claude.json 2>/dev/null', timeout: 10, asRoot: true);
    }

    private function pushMcpConfig(string $containerName): void
    {
        $mcpConfigPath = config('yak.mcp_config_path');

        if ($mcpConfigPath === null || $mcpConfigPath === '' || ! file_exists($mcpConfigPath)) {
            return;
        }

        Process::run(sprintf(
            'incus file push %s %s/home/yak/mcp-config.json',
            escapeshellarg($mcpConfigPath),
            escapeshellarg($containerName),
        ));
    }

    private function exec(string $command): void
    {
        $result = Process::run($command);

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Incus command failed: {$command}\n{$result->errorOutput()}");
        }
    }

    /**
     * Build the `incus exec` shell command, wrapping the payload in
     * `sudo -u yak` unless the caller explicitly needs root.
     */
    private function buildExecCommand(string $containerName, string $command, bool $asRoot): string
    {
        $shell = $asRoot
            ? 'bash -c ' . escapeshellarg($command)
            : 'sudo -u yak -H bash -c ' . escapeshellarg($command);

        return sprintf(
            'incus exec %s -- %s',
            escapeshellarg($containerName),
            $shell,
        );
    }
}
