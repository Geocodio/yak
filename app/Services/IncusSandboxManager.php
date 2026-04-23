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

        // Forward opted-in env vars (NODE_AUTH_TOKEN, NPM_TOKEN, etc.)
        // into the container so every `incus exec` process — ours and
        // any the agent spawns — sees them.
        $this->configureEnvironment($containerName);

        // Start the container
        $this->exec("incus start {$containerName}");

        // Wait for the container to be ready (systemd + Docker daemon)
        $this->waitForReady($containerName);

        // Hot-push the host's current yak-browser bundle so walkthrough
        // tasks pick up new builds without rebuilding the Incus base image.
        $this->pushYakBrowser($containerName);

        // Push Claude config into the container
        $this->pushClaudeConfig($containerName);

        // Push MCP config if configured
        $this->pushMcpConfig($containerName);

        // Push Docker registry auth so `docker pull` inside the sandbox
        // can fetch images from private registries without rebuilding locally.
        $this->pushDockerConfig($containerName);

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

        $this->installGlobalGitIgnore($containerName);

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
        $argv = $this->buildExecArgv($containerName, $command, $asRoot);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Array-form proc_open avoids `/bin/sh -c ...`: PHP execs incus
        // directly as its child. Without this, proc_terminate targets
        // the shell wrapper, leaves the real `incus` running as an
        // orphan, and proc_close blocks the worker past the queue's
        // visibility timeout.
        $process = proc_open($argv, $descriptors, $pipes);

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

    /**
     * Inject opted-in env vars into the Incus container via
     * `incus config set environment.*`. These values are passed to
     * every process started by `incus exec`, so `claude` and anything
     * it spawns (npm install, composer, pip, etc.) inherit them.
     *
     * The list of names comes from `yak.agent_passthrough_env`
     * (populated from Ansible vault's `agent_extra_env`). Values are
     * read from the Yak container's own env via getenv(). App
     * secrets (DB_PASSWORD, APP_KEY, etc.) are never forwarded —
     * only names explicitly listed get through.
     */
    private function configureEnvironment(string $containerName): void
    {
        $passthrough = (string) config('yak.agent_passthrough_env', '');
        if ($passthrough === '') {
            return;
        }

        foreach (array_filter(array_map('trim', explode(',', $passthrough))) as $name) {
            $value = getenv($name);
            if ($value === false) {
                continue;
            }

            $this->exec(sprintf(
                'incus config set %s %s=%s',
                escapeshellarg($containerName),
                escapeshellarg("environment.{$name}"),
                escapeshellarg($value),
            ));
        }
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

    /**
     * Pull rotated Claude OAuth credentials out of a sandbox before teardown.
     *
     * Claude Code's OAuth refresh tokens rotate on every use: each refresh
     * invalidates the prior refresh token server-side. When a sandbox's
     * Claude CLI rotates (access-token expiry during the task, or normal
     * lazy refresh), the new refresh token is written to the sandbox's
     * copy of `.credentials.json` — and unless we pull it back before
     * destroying the container, it's lost, leaving the host holding a
     * refresh token that now 401s. That's the root cause of the recurring
     * "Invalid authentication credentials" task failures.
     *
     * Adoption is gated on `expiresAt` so a pull-back from a sandbox that
     * never rotated can't clobber a host file that a concurrent sandbox
     * already updated. Best-effort: any failure is logged and swallowed
     * — teardown must not be blocked by credential bookkeeping.
     */
    public function pullClaudeCredentials(string $containerName): void
    {
        try {
            $claudeConfigSource = (string) config('yak.sandbox.claude_config_source', '/home/yak/.claude');
            $hostFile = $claudeConfigSource . '/.credentials.json';

            if (! is_file($hostFile)) {
                return;
            }

            $tempFile = $hostFile . '.pull.' . bin2hex(random_bytes(6));

            $result = Process::run(sprintf(
                'incus file pull %s %s 2>/dev/null',
                escapeshellarg($containerName . '/home/yak/.claude/.credentials.json'),
                escapeshellarg($tempFile),
            ));

            try {
                if (! $result->successful() || ! is_file($tempFile)) {
                    return;
                }

                $pulledExpiresAt = $this->extractCredentialsExpiresAt($tempFile);
                if ($pulledExpiresAt === null) {
                    return;
                }

                $lockHandle = @fopen($hostFile, 'r');
                if ($lockHandle === false) {
                    return;
                }

                try {
                    if (! flock($lockHandle, LOCK_EX)) {
                        return;
                    }

                    $hostExpiresAt = $this->extractCredentialsExpiresAt($hostFile);
                    if ($hostExpiresAt !== null && $pulledExpiresAt <= $hostExpiresAt) {
                        return;
                    }

                    if (! @rename($tempFile, $hostFile)) {
                        return;
                    }

                    @chmod($hostFile, 0600);

                    Log::channel('yak')->info('Adopted rotated Claude credentials from sandbox', [
                        'container' => $containerName,
                        'pulled_expires_at' => $pulledExpiresAt,
                        'host_expires_at' => $hostExpiresAt,
                    ]);
                } finally {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                }
            } finally {
                if (is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('pullClaudeCredentials failed', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractCredentialsExpiresAt(string $path): ?int
    {
        $contents = @file_get_contents($path);
        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            return null;
        }

        $expiresAt = $decoded['claudeAiOauth']['expiresAt'] ?? null;

        return is_int($expiresAt) ? $expiresAt : null;
    }

    /**
     * Write a global git ignore file that excludes sandbox-only artifacts
     * from every commit inside the container.
     *
     * Git reads `~/.config/git/ignore` automatically (XDG), so this applies
     * to every repo without needing a `core.excludesFile` flag. Yak pulls
     * `.yak-artifacts/` out of the sandbox before commit and attaches the
     * files to the PR, so they must never end up in git.
     */
    private function installGlobalGitIgnore(string $containerName): void
    {
        $lines = [
            '# Managed by Yak - do not edit.',
            '# Yak collects these out-of-band and attaches them to the PR.',
            '.yak-artifacts/',
        ];

        $remotePath = '/home/yak/.config/git/ignore';
        $printfArgs = implode(' ', array_map(
            fn (string $line): string => escapeshellarg($line),
            $lines,
        ));

        $this->run(
            $containerName,
            sprintf(
                "mkdir -p %s && printf '%%s\n' %s > %s",
                escapeshellarg(dirname($remotePath)),
                $printfArgs,
                escapeshellarg($remotePath),
            ),
            timeout: 10,
        );
    }

    /**
     * Push the host's current yak-browser bundle into the freshly-created
     * sandbox, overwriting the baked fallback. On failure, log a warning
     * and continue — the baked version installed at image-build time keeps
     * walkthroughs working even if the hot-update can't land.
     */
    private function pushYakBrowser(string $containerName): void
    {
        $bundlePath = base_path('sandbox-tools/yak-browser/dist/yak-browser.js');

        if (! file_exists($bundlePath)) {
            Log::channel('yak')->warning('yak-browser bundle missing on host; sandbox will use baked fallback', [
                'expected' => $bundlePath,
                'container' => $containerName,
            ]);

            return;
        }

        try {
            $this->pushFile($containerName, $bundlePath, '/usr/local/bin/yak-browser');
            $this->run(
                $containerName,
                'chmod +x /usr/local/bin/yak-browser',
                timeout: 10,
                asRoot: true,
            );
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('yak-browser hot-update failed; sandbox will use baked fallback', [
                'container' => $containerName,
                'error' => $e->getMessage(),
            ]);
        }
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

    /**
     * Push the host's Docker client config (~/.docker/config.json) into the
     * sandbox so `docker pull` can fetch from private registries without
     * rebuilding images locally. The file holds base64-encoded auth tokens
     * per registry; ansible renders it from vault credentials.
     *
     * Silently skips when the host file doesn't exist — repos that only need
     * public images keep working unchanged.
     */
    private function pushDockerConfig(string $containerName): void
    {
        $dockerConfigSource = (string) config('yak.sandbox.docker_config_source', '/home/yak/.docker/config.json');

        if (! file_exists($dockerConfigSource)) {
            return;
        }

        $this->run(
            $containerName,
            'mkdir -p /home/yak/.docker',
            timeout: 10,
            asRoot: true,
        );

        Process::run(sprintf(
            'incus file push %s %s/home/yak/.docker/config.json',
            escapeshellarg($dockerConfigSource),
            escapeshellarg($containerName),
        ));

        // `incus file push` lands files as root; chown + tighten perms so
        // the embedded auth tokens aren't world-readable inside the sandbox.
        $this->run(
            $containerName,
            'chown -R yak:yak /home/yak/.docker && chmod 600 /home/yak/.docker/config.json',
            timeout: 10,
            asRoot: true,
        );
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
        if ($asRoot) {
            // `incus exec` runs as root by default and preserves the
            // container's environment, so no further wrapping needed.
            $shell = 'bash -c ' . escapeshellarg($command);
        } else {
            // sudo's default env_reset scrubs everything except a
            // small allowlist, which would eat the agent passthrough
            // vars we just set on the container. --preserve-env=<list>
            // whitelists exactly the ones we forwarded.
            $preserve = $this->preserveEnvFlag();
            $shell = 'sudo -u yak' . $preserve . ' -H bash -c ' . escapeshellarg($command);
        }

        return sprintf(
            'incus exec %s -- %s',
            escapeshellarg($containerName),
            $shell,
        );
    }

    /**
     * Argv form of the same command, for proc_open without a shell.
     *
     * @return list<string>
     */
    private function buildExecArgv(string $containerName, string $command, bool $asRoot): array
    {
        $tail = $asRoot
            ? ['bash', '-c', $command]
            : array_merge(
                ['sudo', '-u', 'yak'],
                $this->preserveEnvArgs(),
                ['-H', 'bash', '-c', $command],
            );

        return array_merge(['incus', 'exec', $containerName, '--'], $tail);
    }

    /**
     * Build the `--preserve-env=NAME1,NAME2` flag for sudo, based on
     * `yak.agent_passthrough_env`. Returns an empty string when
     * nothing is configured so the sudo invocation stays clean.
     */
    private function preserveEnvFlag(): string
    {
        $passthrough = (string) config('yak.agent_passthrough_env', '');
        if ($passthrough === '') {
            return '';
        }

        $names = array_filter(array_map('trim', explode(',', $passthrough)));
        if ($names === []) {
            return '';
        }

        return ' --preserve-env=' . escapeshellarg(implode(',', $names));
    }

    /**
     * Argv form of preserveEnvFlag — returns 0 or 1 element.
     *
     * @return list<string>
     */
    private function preserveEnvArgs(): array
    {
        $passthrough = (string) config('yak.agent_passthrough_env', '');
        if ($passthrough === '') {
            return [];
        }

        $names = array_filter(array_map('trim', explode(',', $passthrough)));
        if ($names === []) {
            return [];
        }

        return ['--preserve-env=' . implode(',', $names)];
    }
}
