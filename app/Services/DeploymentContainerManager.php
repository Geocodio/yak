<?php

namespace App\Services;

use App\DataTransferObjects\PreviewManifest;
use App\DataTransferObjects\TemplateSnapshotRef;
use App\Exceptions\DeploymentStartTimeoutException;
use App\Models\BranchDeployment;
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

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Failed to start container: {$result->errorOutput()}");
        }

        if ($manifest->coldStart !== '') {
            $this->exec($deployment->container_name, $manifest->coldStart, $manifest->coldStartTimeoutSeconds);
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
        $container = $deployment->container_name;
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');

        $this->exec($container, "cd {$workspace} && git fetch --all --prune", $manifest->checkoutRefreshTimeoutSeconds);
        $this->exec($container, "cd {$workspace} && git checkout --force {$commitSha}", $manifest->checkoutRefreshTimeoutSeconds);

        $hasRepoHook = Process::run("incus exec {$container} -- test -f {$workspace}/.yak/preview.sh")
            ->exitCode() === 0;

        if ($hasRepoHook) {
            $this->exec($container, "{$workspace}/.yak/preview.sh {$commitSha}", $manifest->checkoutRefreshTimeoutSeconds);
        } elseif ($manifest->checkoutRefresh !== '') {
            $this->exec($container, $manifest->checkoutRefresh, $manifest->checkoutRefreshTimeoutSeconds);
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

    private function exec(string $container, string $command, int $timeoutSeconds): void
    {
        $result = Process::timeout($timeoutSeconds)
            ->run("incus exec {$container} -- bash -lc " . escapeshellarg($command));

        if ($result->exitCode() !== 0) {
            throw new RuntimeException("Command failed in container {$container}: {$result->errorOutput()}");
        }
    }

    private function waitForHealthProbe(string $ip, int $port, string $path, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $url = sprintf('http://%s:%d%s', $ip, $port, $path);

        while (microtime(true) < $deadline) {
            $response = Http::timeout(2)->get($url);

            if ($response->successful()) {
                return;
            }

            usleep(500_000); // 0.5s
        }

        throw new DeploymentStartTimeoutException('health_probe', "Health probe never returned 2xx at {$url}");
    }
}
