<?php

namespace App\Services;

use App\DataTransferObjects\PreviewManifest;
use App\DataTransferObjects\TemplateSnapshotRef;
use App\Exceptions\DeploymentStartTimeoutException;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DeploymentContainerManager
{
    public function __construct(private IncusSandboxManager $sandbox) {}

    public function createFromTemplate(BranchDeployment $deployment): void
    {
        $deployment->loadMissing('repository');
        $ref = new TemplateSnapshotRef($deployment->repository->slug, $deployment->template_version);

        $result = Process::run("incus copy {$ref->name()} {$deployment->container_name}");

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Failed to clone template snapshot: {$result->errorOutput()}");
        }
    }

    public function start(BranchDeployment $deployment): string
    {
        $deployment->loadMissing('repository');
        $manifest = PreviewManifest::fromArray($deployment->repository->preview_manifest);

        $result = Process::run("incus start {$deployment->container_name}");

        // Idempotent: DeployBranchJob and DeploymentWaker both call start()
        // without coordinating. If the Job starts the container and sets
        // status=Running afterwards, a preview request arriving in that
        // window triggers Waker, which sees status!=Running and calls
        // start() a second time. Treat "already running" as success.
        if ($result->exitCode() !== 0
            && ! str_contains(strtolower($result->errorOutput()), 'already running')
        ) {
            throw new RuntimeException("Failed to start container: {$result->errorOutput()}");
        }

        if ($manifest->coldStart !== '') {
            $this->exec($deployment, 'cold_start', $manifest->coldStart, $manifest->coldStartTimeoutSeconds);
        }

        $ip = $this->resolveContainerIp($deployment->container_name);

        $this->waitForHealthProbe(
            $ip,
            $manifest->port,
            $manifest->healthProbePath,
            $manifest->healthProbeTimeoutSeconds,
        );

        return $ip;
    }

    public function resolveContainerIp(string $containerName): string
    {
        $result = Process::run("incus list {$containerName} -c n,4 -f csv");

        if ($result->exitCode() !== 0) {
            throw new DeploymentStartTimeoutException('ip_resolution', "Failed to list container {$containerName}");
        }

        $line = trim($result->output());

        // `incus list -c 4` returns one "ip (iface)" entry per line per
        // container NIC. We want the Incus-bridge-facing interface (eth0),
        // not any docker0 / br-<hash> the container might create when the
        // app spins up its own Docker daemon. Those inner-bridge IPs
        // (172.17.x.x, 172.18.x.x) happen to sort first in the CSV and
        // are unreachable from the Yak container's network.
        if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\s*\(eth0\)/', $line, $m)) {
            return $m[1];
        }

        throw new DeploymentStartTimeoutException('ip_resolution', "No eth0 IPv4 for container {$containerName}");
    }

    public function applyCheckoutRefresh(BranchDeployment $deployment, string $commitSha): void
    {
        $deployment->loadMissing('repository');
        $manifest = PreviewManifest::fromArray($deployment->repository->preview_manifest);
        $workspace = IncusSandboxManager::workspacePath();

        // GitHub App installation tokens TTL out after ~1 hour, so the
        // helper baked into the template at setup time is stale by the
        // time we re-fetch. Re-inject a fresh token before each fetch.
        // Routed through IncusSandboxManager (not exec()) so the token
        // never lands in deployment_logs.
        $this->sandbox->injectGitCredentials($deployment->container_name);

        // cold_start typically runs `docker compose up`, whose inner
        // containers bind-mount /workspace and create directories
        // (Laravel's bootstrap/cache, storage/*) as their own user
        // (root or www-data). Yak then can't unlink files inside those
        // dirs during checkout. Reclaim ownership as root first.
        $this->exec($deployment, 'reclaim_workspace', "chown -R yak:yak {$workspace}", $manifest->checkoutRefreshTimeoutSeconds, asRoot: true);

        $this->exec($deployment, 'fetch', "cd {$workspace} && git fetch --all --prune", $manifest->checkoutRefreshTimeoutSeconds);
        $this->exec($deployment, 'checkout', "cd {$workspace} && git checkout --force {$commitSha}", $manifest->checkoutRefreshTimeoutSeconds);

        $hasRepoHook = Process::run("incus exec {$deployment->container_name} -- test -f {$workspace}/.yak/preview.sh")
            ->exitCode() === 0;

        if ($hasRepoHook) {
            $this->exec($deployment, 'refresh', "{$workspace}/.yak/preview.sh {$commitSha}", $manifest->checkoutRefreshTimeoutSeconds);
        } elseif ($manifest->checkoutRefresh !== '') {
            $this->exec($deployment, 'refresh', $manifest->checkoutRefresh, $manifest->checkoutRefreshTimeoutSeconds);
        }

        $deployment->update([
            'current_commit_sha' => $commitSha,
            'dirty' => false,
        ]);
    }

    public function stop(BranchDeployment $deployment): void
    {
        $result = Process::run("incus stop {$deployment->container_name}");

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Failed to stop container: {$result->errorOutput()}");
        }
    }

    public function destroy(BranchDeployment $deployment): void
    {
        $result = Process::run("incus delete --force {$deployment->container_name}");

        if (! $result->successful() && ! str_contains(strtolower($result->errorOutput()), 'not found')) {
            throw new RuntimeException("Failed to destroy container: {$result->errorOutput()}");
        }
    }

    private function exec(BranchDeployment $deployment, string $phase, string $command, int $timeoutSeconds, bool $asRoot = false): void
    {
        $container = $deployment->container_name;
        $startedAt = microtime(true);

        // Phase row carries the command and meta. Streaming output lands
        // in deployment_log_chunks (INSERT-only, longText) so a chatty
        // docker build doesn't get capped or hammer UPDATEs on a growing
        // row. The deployment page concatenates chunks for display, like
        // a CI log viewer.
        $log = DeploymentLog::create([
            'branch_deployment_id' => $deployment->id,
            'level' => 'info',
            'phase' => $phase,
            'message' => "\$ {$command}",
            'metadata' => null,
        ]);

        $buffer = '';
        $lastFlush = microtime(true);

        $flush = function () use (&$buffer, $log): void {
            if ($buffer === '') {
                return;
            }
            $log->chunks()->create([
                'chunk' => $buffer,
                'created_at' => now(),
            ]);
            $buffer = '';
        };

        $result = $this->sandbox->run(
            $container,
            $command,
            timeout: $timeoutSeconds,
            asRoot: $asRoot,
            output: function (string $type, string $chunk) use (&$buffer, &$lastFlush, $flush): void {
                $buffer .= $chunk;
                // Throttle: at most one chunk row per 500ms or when the
                // buffer crosses 4 KiB. Keeps row count reasonable on
                // very chatty builds without losing live-feel.
                $now = microtime(true);
                if ($now - $lastFlush > 0.5 || strlen($buffer) > 4096) {
                    $flush();
                    $lastFlush = $now;
                }
            },
        );

        $flush();

        // Fallback: when streaming produced no chunks (Process::fake
        // doesn't drive the callback, and very fast commands may also
        // exit before the first flush), insert the final captured
        // stdout/stderr as a single chunk so the UI still reflects it.
        if (! $log->chunks()->exists()) {
            $combined = trim($result->output() . "\n" . $result->errorOutput());
            if ($combined !== '') {
                $log->chunks()->create([
                    'chunk' => $combined,
                    'created_at' => now(),
                ]);
            }
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $log->update([
            'level' => $result->exitCode() === 0 ? 'info' : 'error',
            'metadata' => ['exit_code' => $result->exitCode(), 'duration_ms' => $durationMs],
        ]);

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Command failed in container {$container} (phase={$phase}): {$result->errorOutput()}");
        }
    }

    private function waitForHealthProbe(string $ip, int $port, string $path, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $url = sprintf('http://%s:%d%s', $ip, $port, $path);

        while (microtime(true) < $deadline) {
            try {
                $response = Http::timeout(2)->get($url);

                if ($response->successful()) {
                    return;
                }
            } catch (ConnectionException) {
                // Connection refused / DNS failure while the in-sandbox
                // application stack is still booting (e.g. nginx/docker
                // proxy not yet bound). Keep polling until the deadline.
            }

            usleep(500_000); // 0.5s
        }

        throw new DeploymentStartTimeoutException('health_probe', "Health probe never returned 2xx at {$url}");
    }
}
