<?php

namespace App\Services;

use App\DataTransferObjects\PreviewManifest;
use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeploymentWaker
{
    public function __construct(private readonly DeploymentContainerManager $manager) {}

    /**
     * Ensure the deployment is ready for traffic.
     *
     * @return array{state: 'ready'|'pending'|'failed', host?: string, port?: int, reason?: string}
     */
    public function ensureReady(BranchDeployment $deployment): array
    {
        $deployment->loadMissing('repository');

        if ($deployment->status === DeploymentStatus::Running) {
            return $this->ready($deployment);
        }

        if (in_array($deployment->status, [DeploymentStatus::Destroyed, DeploymentStatus::Destroying], true)) {
            return ['state' => 'failed', 'reason' => 'Deployment has been destroyed.'];
        }

        $lock = Cache::lock("yak:wake:{$deployment->id}", seconds: 30);

        if (! $lock->get()) {
            // Someone else is already waking; wait briefly then re-check.
            $lock->block((int) config('yak.deployments.wake_request_budget_seconds'));
            $deployment->refresh();

            return $deployment->status === DeploymentStatus::Running
                ? $this->ready($deployment)
                : ['state' => 'pending'];
        }

        try {
            $deadline = microtime(true) + (int) config('yak.deployments.wake_request_budget_seconds');

            $deployment->status = DeploymentStatus::Starting;
            $deployment->save();

            $host = $this->manager->start($deployment);

            if ($deployment->dirty && $deployment->current_commit_sha !== null) {
                $this->manager->applyCheckoutRefresh($deployment, $deployment->current_commit_sha);
            }

            $deployment->status = DeploymentStatus::Running;
            $deployment->last_accessed_at = now();
            $deployment->save();

            if (microtime(true) > $deadline) {
                return ['state' => 'pending'];
            }

            return [
                'state' => 'ready',
                'host' => $host,
                'port' => $this->manifestPort($deployment),
            ];
        } catch (\Throwable $e) {
            Log::error('Deployment wake failed', [
                'deployment_id' => $deployment->id,
                'error' => $e->getMessage(),
            ]);
            $deployment->status = DeploymentStatus::Failed;
            $deployment->failure_reason = 'wake: ' . $e->getMessage();
            $deployment->save();

            return ['state' => 'failed', 'reason' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{state: 'ready', host: string, port: int}
     */
    private function ready(BranchDeployment $deployment): array
    {
        return [
            'state' => 'ready',
            'host' => $this->manager->resolveContainerIp($deployment->container_name),
            'port' => $this->manifestPort($deployment),
        ];
    }

    private function manifestPort(BranchDeployment $deployment): int
    {
        return PreviewManifest::fromArray($deployment->repository->preview_manifest)->port;
    }
}
