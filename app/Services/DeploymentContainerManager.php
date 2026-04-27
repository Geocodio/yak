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
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');

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

        // Default to the `yak` user so file ownership and git's
        // safe.directory check stay consistent with the template (which
        // was built by Yak-task jobs running as `yak` via
        // IncusSandboxManager). `incus exec` would otherwise run as
        // root, breaking git operations against a yak-owned workspace.
        $shell = $asRoot
            ? 'bash -lc ' . escapeshellarg($command)
            : 'sudo -u yak -H bash -lc ' . escapeshellarg($command);

        $result = Process::timeout($timeoutSeconds)
            ->run("incus exec {$container} -- {$shell}");

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $combined = trim($result->output() . "\n" . $result->errorOutput());

        // Cap at 64 KiB per entry — enough to eyeball but bounds the row size.
        if (strlen($combined) > 65_536) {
            $combined = substr($combined, 0, 65_536) . "\n[… truncated]";
        }

        DeploymentLog::record(
            deployment: $deployment,
            level: $result->exitCode() === 0 ? 'info' : 'error',
            phase: $phase,
            message: "\$ {$command}" . ($combined === '' ? '' : "\n{$combined}"),
            metadata: ['exit_code' => $result->exitCode(), 'duration_ms' => $durationMs],
        );

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
