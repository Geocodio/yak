<?php

namespace App\Services;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\DataTransferObjects\TemplateSnapshotRef;
use App\Enums\DeploymentStatus;
use App\Enums\TaskStatus;
use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Models\BranchDeployment;
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
     * Wall-clock ceiling for incus commands that move real data — `copy`,
     * `snapshot create`, `config device add`. Cloning a fully provisioned
     * repo template is fast on ZFS but not instant, and Laravel's Process
     * facade defaults to a 60s timeout that these routinely blow past.
     */
    private const EXEC_TIMEOUT = 600;

    /**
     * How long to let a container shut down cleanly before killing it.
     * `incus stop` defaults to `--timeout -1` (wait forever), so this has
     * to be bounded on our side or the shutdown of a container holding a
     * stuck dev server hangs until the Process facade throws.
     */
    private const STOP_TIMEOUT = 60;

    /** Ceiling for `incus delete`, which unwinds ZFS datasets. */
    private const DELETE_TIMEOUT = 300;

    /** Ceiling for a single readiness probe inside a booting container. */
    private const PROBE_TIMEOUT = 10;

    /** Ceiling for cheap metadata reads — `list`, `info`, `config`, `file push`. */
    private const QUERY_TIMEOUT = 30;

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

        // Share the host Claude config directory. Must precede `incus start`:
        // raw.idmap is only applied when the container boots.
        $this->configureClaudeMount($containerName);

        // Start the container
        $this->exec("incus start {$containerName}");

        // Wait for the container to be ready (systemd + Docker daemon)
        $this->waitForReady($containerName);

        // Hot-push the host's current yak-browser bundle so walkthrough
        // tasks pick up new builds without rebuilding the Incus base image.
        $this->pushYakBrowser($containerName);

        // Push MCP config if configured
        $this->pushMcpConfig($containerName);

        // Push Docker registry auth so `docker pull` inside the sandbox
        // can fetch images from private registries without rebuilding locally.
        $this->pushDockerConfig($containerName);

        // Normalize /workspace ownership so every git operation — whether
        // run by the agent or by job code — sees a consistently yak-owned
        // tree. Legacy templates were built with `git clone` as root, which
        // left `.git` root-owned and tripped git's dubious-ownership check.
        $this->run(
            $containerName,
            'chown -R yak:yak ' . escapeshellarg(self::workspacePath()),
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
    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null, ?callable $output = null): ProcessResult
    {
        $cmd = $this->buildExecCommand($containerName, $command, $asRoot);

        $process = Process::timeout($timeout ?? 600);

        if ($input !== null) {
            $process = $process->input($input);
        }

        // The optional `$output` callback receives ('out'|'err', $chunk)
        // for each stdout/stderr buffer flush — used by deployment refresh
        // to stream live progress into deployment_logs while long-running
        // builds (docker build, composer install, etc.) are still running.
        return $output !== null
            ? $process->run($cmd, $output)
            : $process->run($cmd);
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
     * Workspace mount point inside every sandbox container.
     *
     * Single source of truth for the path that callers use to scope
     * `cd …` / `git -C` operations. Static so non-sandbox-holding code
     * (e.g. agent runners that only have a YakTask in scope) can read
     * it without resolving the manager from the container.
     */
    public static function workspacePath(): string
    {
        return (string) config('yak.sandbox.workspace_path', '/workspace');
    }

    /**
     * Set git user.name and user.email globally inside the sandbox.
     *
     * Required by jobs that produce commits in-sandbox. Read-only callers
     * (fetch/checkout) don't need this — call {@see injectGitCredentials}
     * alone instead.
     */
    public function configureGitIdentity(string $containerName): void
    {
        $gitName = config('yak.git_user_name', 'Yak');
        $gitEmail = config('yak.git_user_email', 'yak@noreply.github.com');

        $this->run($containerName, 'git config --global user.name ' . escapeshellarg($gitName), timeout: 10);
        $this->run($containerName, 'git config --global user.email ' . escapeshellarg($gitEmail), timeout: 10);
    }

    /**
     * Inject a fresh GitHub App installation token into the sandbox as a
     * git credential helper for github.com.
     *
     * Tokens TTL out after ~1 hour, so callers should re-invoke this
     * before each git network operation rather than relying on the
     * helper baked in at template-build time.
     *
     * No-op when no installation_id is configured (e.g. local dev).
     */
    public function injectGitCredentials(string $containerName): void
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            return;
        }

        $token = app(GitHubAppService::class)->getInstallationToken($installationId);

        $this->run(
            $containerName,
            'git config --global credential.https://github.com.helper '
            . escapeshellarg("!f() { echo \"protocol=https\nhost=github.com\nusername=x-access-token\npassword={$token}\"; }; f"),
            timeout: 10,
        );
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
        $this->stopBeforeCapture($containerName);

        // Delete existing snapshot if present (idempotent re-snapshot)
        Process::timeout(self::DELETE_TIMEOUT)->run("incus snapshot delete {$containerName} {$snapshotName} 2>/dev/null");

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
        $newVersion = max((int) ($repository->current_template_version ?? 0), 0) + 1;
        $snapshotName = "ready-v{$newVersion}";

        // Delete old template if it exists
        Process::timeout(self::DELETE_TIMEOUT)->run("incus delete {$templateName} --force 2>/dev/null");

        // Stop the task container
        $this->stopBeforeCapture($containerName);

        // `incus copy` carries instance-local devices and config to the
        // copy. Strip the shared claude credential mount and its idmap
        // before promoting, so the template (and every sandbox cloned from
        // it) doesn't inherit a live read/write mount of the host's
        // ~/.claude. The container is stopped and about to be destroyed,
        // so this is safe; best-effort since a container that never had
        // the device/idmap must not fail the promotion.
        Process::timeout(self::QUERY_TIMEOUT)->run("incus config device remove {$containerName} claude 2>/dev/null");
        Process::timeout(self::QUERY_TIMEOUT)->run("incus config unset {$containerName} raw.idmap 2>/dev/null");

        // Copy task container as the new template
        $this->exec("incus copy {$containerName} {$templateName}");

        // Snapshot the template with the versioned snapshot name
        $this->exec("incus snapshot create {$templateName} {$snapshotName}");

        $ref = new TemplateSnapshotRef($repository->slug, $newVersion);

        // Stamp the repo with the yak-base version the template inherits
        // from. A later bump to config('yak.sandbox.base_version') will
        // trigger ensureTemplateVersionCurrent() to invalidate this template.
        $repository->update([
            'sandbox_base_version' => (int) config('yak.sandbox.base_version', 1),
            'current_template_version' => $newVersion,
        ]);

        Log::channel('yak')->info('Promoted sandbox to repo template', [
            'source' => $containerName,
            'template' => $templateName,
            'snapshot' => $snapshotName,
            'base_version' => $repository->sandbox_base_version,
            'template_version' => $newVersion,
        ]);

        // IMPORTANT: `sandbox_snapshot` must now hold the versioned ref, so
        // `resolveSource()` keeps working for the task-sandbox clone path.
        $repository->update(['sandbox_snapshot' => $ref->name()]);

        // Rebuild any non-destroyed deployments still pinned to an older
        // template_version. Until they're rebased, the prior template's
        // ZFS dataset is held alive under incus-pool/deleted/, since
        // ZFS clones reference the parent's @copy snapshot.
        $hasStaleClones = BranchDeployment::query()
            ->where('repository_id', $repository->id)
            ->whereNotIn('status', [
                DeploymentStatus::Destroyed->value,
                DeploymentStatus::Destroying->value,
            ])
            ->where('template_version', '<', $newVersion)
            ->exists();

        if ($hasStaleClones) {
            RebuildRepositoryDeploymentsJob::dispatch($repository->id);
        }

        return $ref->name();
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
        Process::timeout(self::DELETE_TIMEOUT)->run("incus delete {$containerName} --force 2>/dev/null");
    }

    /**
     * Check if a repo has a sandbox snapshot ready for cloning.
     */
    public function hasSnapshot(Repository $repository): bool
    {
        $templateName = $this->templateName($repository);
        $snapshotName = (string) config('yak.sandbox.snapshot_name', 'ready');

        $result = Process::timeout(self::QUERY_TIMEOUT)->run("incus snapshot list {$templateName} --format csv 2>/dev/null");

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
     * Delete leftover task and deployment sandbox containers.
     *
     * Covers four cases:
     *  - STOPPED task-* containers (normal leftovers from completed jobs)
     *  - RUNNING task-* containers whose YakTask is in a terminal state
     *    (orphans from worker hard-kills on timeout — the `finally` block
     *    never got a chance to destroy the container)
     *  - deploy-* containers with no matching branch_deployments row
     *    (the row was deleted but DestroyDeploymentJob never ran or
     *    failed mid-way)
     *  - deploy-* containers whose branch_deployments row is Destroyed
     *    (the row finished its lifecycle but the container survived)
     */
    public function cleanupStale(): int
    {
        $result = Process::timeout(self::QUERY_TIMEOUT)->run('incus list --format csv -c n,s 2>/dev/null');

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

            if (str_starts_with($name, 'task-')) {
                if ($status === 'STOPPED') {
                    Process::timeout(self::DELETE_TIMEOUT)->run("incus delete {$name} --force");
                    $deleted++;

                    continue;
                }

                if ($status === 'RUNNING' && $this->isOrphaned($name)) {
                    Log::channel('yak')->warning('Cleaning up orphaned sandbox', [
                        'container' => $name,
                    ]);
                    Process::timeout(self::DELETE_TIMEOUT)->run("incus delete {$name} --force");
                    $deleted++;
                }

                continue;
            }

            if (str_starts_with($name, 'deploy-') && $this->isDeploymentOrphan($name)) {
                Log::channel('yak')->warning('Cleaning up orphaned deployment sandbox', [
                    'container' => $name,
                    'status' => $status,
                ]);
                Process::timeout(self::DELETE_TIMEOUT)->run("incus delete {$name} --force");
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
        $result = Process::timeout(self::QUERY_TIMEOUT)->run('incus info ' . escapeshellarg($containerName));

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

        Process::timeout(self::DELETE_TIMEOUT)->run("incus delete {$templateName} --force 2>/dev/null");

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
     * True when a deploy-* container has no branch_deployments row, or
     * its row has reached Destroyed.
     *
     * Active states (Pending/Starting/Running/Hibernated/Destroying/
     * Failed) are intentionally NOT swept here — they're either in use,
     * paused for hibernation, or about to be destroyed by the proper
     * DestroyDeploymentJob path. SweepExpiredDeploymentsJob handles
     * row-driven destruction; this method handles the inverse case
     * (containers without rows).
     */
    private function isDeploymentOrphan(string $containerName): bool
    {
        $deployment = BranchDeployment::where('container_name', $containerName)->first();

        if ($deployment === null) {
            return true;
        }

        return $deployment->status === DeploymentStatus::Destroyed;
    }

    /**
     * Resolve the source template/snapshot to clone from for a repository.
     *
     * Primary path: use the versioned ref stored by promoteToTemplate().
     * Legacy fallback: repos set up before Phase 5 (no versioned snapshot).
     */
    public function resolveSource(Repository $repository): string
    {
        // Primary: the canonical versioned ref stored by promoteToTemplate.
        if (! empty($repository->sandbox_snapshot)) {
            return $repository->sandbox_snapshot;
        }

        // Legacy fallback: repos set up before Phase 5 (before versioned snapshots).
        $templateName = $this->templateName($repository);
        $snapshotName = (string) config('yak.sandbox.snapshot_name', 'ready');

        if ($this->hasSnapshot($repository)) {
            return "{$templateName}/{$snapshotName}";
        }

        // Fall back to base template
        $baseTemplate = (string) config('yak.sandbox.base_template', 'yak-base');
        $baseResult = Process::timeout(self::QUERY_TIMEOUT)->run("incus snapshot list {$baseTemplate} --format csv 2>/dev/null");

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
        Process::timeout(self::QUERY_TIMEOUT)->run("incus config device set {$containerName} root size={$disk} 2>/dev/null");
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

    /**
     * Run a short readiness probe inside a booting container.
     *
     * Returns false rather than throwing when the probe times out or errors,
     * so the caller's polling loop gets another turn.
     *
     * @param  list<string>  $expectedOutput  when given, trimmed stdout must match one of these
     */
    private function probeSucceeds(string $command, array $expectedOutput = []): bool
    {
        try {
            $result = Process::timeout(self::PROBE_TIMEOUT)->run($command);
        } catch (\Throwable) {
            return false;
        }

        if ($expectedOutput === []) {
            return $result->exitCode() === 0;
        }

        return in_array(trim($result->output()), $expectedOutput, true);
    }

    private function waitForReady(string $containerName, int $maxWaitSeconds = 60): void
    {
        $start = time();

        while (time() - $start < $maxWaitSeconds) {
            // A probe that hangs is just a not-ready-yet signal, so cap each
            // one well under $maxWaitSeconds and keep polling. Without the
            // cap these inherit the Process facade's 60s default, and a
            // single stuck probe outlives the whole wait window.
            if ($this->probeSucceeds("incus exec {$containerName} -- systemctl is-system-running 2>/dev/null", ['running', 'degraded'])
                && $this->probeSucceeds("incus exec {$containerName} -- docker info 2>/dev/null")) {
                return;
            }

            usleep(500_000); // 500ms
        }

        throw new RuntimeException("Sandbox {$containerName} did not become ready within {$maxWaitSeconds}s");
    }

    /**
     * Share the host's Claude config directory with the sandbox instead of
     * copying it.
     *
     * Claude Code's OAuth refresh tokens are single-use: each refresh
     * invalidates the previous one server-side. Handing every sandbox a
     * private copy meant the first sandbox to refresh stranded every other
     * copy, which is the "OAuth session expired and could not be refreshed"
     * failure. With one shared directory there is exactly one credential
     * file, and the CLI's own `.oauth_refresh.lock` — which lives in that
     * directory — serializes concurrent refreshes the way it was designed to.
     *
     * `raw.idmap` maps the host uid that owns the directory onto the
     * container's `yak` user, so the agent can read and write it and its
     * writes land host-side owned by www-data. It only takes effect at
     * container start, so this must run before `incus start`.
     */
    private function configureClaudeMount(string $containerName): void
    {
        $source = (string) config('yak.sandbox.claude_config_source', '/home/yak/.claude');

        // fileowner() can return false on a stat failure even when is_dir()
        // is true; (int) false is 0, which would idmap the container's yak
        // user onto host root. Only trust it when it actually resolved.
        $owner = is_dir($source) ? fileowner($source) : false;

        $hostUid = is_int($owner)
            ? $owner
            : (int) config('yak.sandbox.claude_host_uid', 33);

        $sandboxUid = (int) config('yak.sandbox.claude_sandbox_uid', 1001);

        $this->exec(sprintf(
            'incus config set %s raw.idmap %s',
            escapeshellarg($containerName),
            escapeshellarg(sprintf('both %d %d', $hostUid, $sandboxUid)),
        ));

        // Idempotent: a sandbox cloned from a template that was poisoned by
        // an earlier build of this feature (see promoteToTemplate()) may
        // already carry the device. Remove it first so the add below
        // doesn't fail with "Device already exists".
        Process::timeout(self::QUERY_TIMEOUT)->run(sprintf(
            'incus config device remove %s claude 2>/dev/null',
            escapeshellarg($containerName),
        ));

        $this->exec(sprintf(
            'incus config device add %s claude disk source=%s path=%s',
            escapeshellarg($containerName),
            escapeshellarg($source),
            escapeshellarg($source),
        ));
    }

    /**
     * Pull a Claude session transcript out of a sandbox before teardown so
     * a later follow-up or clarification reply can `--resume` the session
     * in a fresh sandbox. Transcripts live under `~/.claude/projects/`
     * inside the container and are destroyed with it otherwise.
     *
     * Best-effort: any failure is logged and swallowed — teardown must not
     * be blocked by transcript bookkeeping.
     */
    public function pullSessionTranscript(string $containerName, ?string $sessionId): void
    {
        if ($sessionId === null || $sessionId === '') {
            return;
        }

        try {
            $findResult = $this->run(
                $containerName,
                sprintf('find /home/yak/.claude/projects -name %s 2>/dev/null | head -1', escapeshellarg($sessionId . '.jsonl')),
                timeout: 15,
            );

            $remotePath = trim((string) $findResult->output());

            if ($remotePath === '') {
                return;
            }

            $localDir = self::sessionTranscriptDir();
            if (! is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $this->pullFile($containerName, $remotePath, $localDir . '/' . $sessionId . '.jsonl');

            Log::channel('yak')->info('Persisted Claude session transcript', [
                'container' => $containerName,
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('pullSessionTranscript failed', [
                'container' => $containerName,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Push a previously persisted session transcript into a fresh sandbox
     * so `claude --resume {sessionId}` can find the conversation. Returns
     * whether a transcript was pushed; when false the caller's resume will
     * fail with "No conversation found" and should fall back to a fresh run.
     */
    public function pushSessionTranscript(string $containerName, ?string $sessionId): bool
    {
        if ($sessionId === null || $sessionId === '') {
            return false;
        }

        $localPath = self::sessionTranscriptDir() . '/' . $sessionId . '.jsonl';

        if (! is_file($localPath)) {
            return false;
        }

        try {
            // Claude Code keys project transcript dirs by the CWD with every
            // non-alphanumeric character replaced by '-' (e.g. /workspace
            // becomes -workspace). The agent always runs from workspacePath().
            $projectDir = '/home/yak/.claude/projects/' . preg_replace('/[^a-zA-Z0-9]/', '-', self::workspacePath());

            $this->run($containerName, 'mkdir -p ' . escapeshellarg($projectDir), timeout: 10, asRoot: true);
            $this->pushFile($containerName, $localPath, $projectDir . '/' . $sessionId . '.jsonl');
            $this->run($containerName, 'chown -R yak:yak /home/yak/.claude/projects', timeout: 10, asRoot: true);

            Log::channel('yak')->info('Restored Claude session transcript into sandbox', [
                'container' => $containerName,
                'session_id' => $sessionId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('pushSessionTranscript failed', [
                'container' => $containerName,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Host directory holding persisted session transcripts.
     */
    public static function sessionTranscriptDir(): string
    {
        return (string) config('yak.sandbox.session_transcript_path', storage_path('app/claude-sessions'));
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

        Process::timeout(self::QUERY_TIMEOUT)->run(sprintf(
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

        Process::timeout(self::QUERY_TIMEOUT)->run(sprintf(
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

    /**
     * Stop a container that is about to be snapshotted, copied, or destroyed.
     *
     * `incus stop` defaults to `--timeout -1`, i.e. wait forever for a clean
     * shutdown. A sandbox that just finished a setup run is usually holding a
     * dev server, a docker daemon, or a stray agent process that never exits,
     * so the graceful path can outlast any wrapper timeout we set. Give it a
     * bounded window and then kill it: the container's only remaining job is
     * to be captured or deleted, so a hard stop costs nothing.
     */
    private function stopBeforeCapture(string $containerName): void
    {
        $graceful = Process::timeout(self::STOP_TIMEOUT + 30)
            ->run("incus stop {$containerName} --timeout " . self::STOP_TIMEOUT);

        if ($graceful->exitCode() === 0) {
            return;
        }

        Log::channel('yak')->warning('Graceful sandbox stop failed, forcing', [
            'container' => $containerName,
            'error' => trim($graceful->errorOutput()),
        ]);

        $this->exec("incus stop {$containerName} --force", timeout: self::STOP_TIMEOUT);
    }

    private function exec(string $command, ?int $timeout = null): void
    {
        $result = Process::timeout($timeout ?? self::EXEC_TIMEOUT)->run($command);

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
