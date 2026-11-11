<?php

namespace App\Services;

use App\DataTransferObjects\PreviewManifest;
use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DeployBranchJob;
use App\Jobs\Deployments\WakeHibernatedDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use Illuminate\Support\Facades\Cache;

class DeploymentWaker
{
    public function __construct(
        private readonly DeploymentContainerManager $manager,
    ) {}

    /**
     * Decide whether the deployment is ready for traffic right now.
     *
     * The wake itself (incus start, cold-start hook, health probe) can take
     * a couple of minutes, far longer than any sane HTTP-request budget.
     * We never run that synchronously — instead we kick off a background
     * job and tell the caller `pending`, which causes Caddy to render the
     * cold-boot shim. The shim auto-refreshes; once the job flips status
     * to Running, the next request resolves to `ready` and Caddy proxies.
     *
     * @return array{state: 'ready', host: string, port: int}|array{state: 'pending'}|array{state: 'failed', reason: string}
     */
    public function ensureReady(BranchDeployment $deployment): array
    {
        $deployment->loadMissing('repository');

        return match ($deployment->status) {
            DeploymentStatus::Running => $this->ready($deployment),

            // A destroyed row of an open PR rebuilds on this hit.
            DeploymentStatus::Destroyed => $deployment->isRedeployable()
                ? $this->dispatchRedeploy($deployment)
                : ['state' => 'failed', 'reason' => 'Deployment has been destroyed.'],

            DeploymentStatus::Destroying => ['state' => 'failed', 'reason' => 'Deployment has been destroyed.'],

            DeploymentStatus::Failed => [
                'state' => 'failed',
                'reason' => $deployment->failure_reason ?? 'Previous wake failed.',
            ],

            // The first deploy is still being provisioned by DeployBranchJob,
            // or another wake is already in flight. Either way the shim
            // will keep refreshing until the worker flips status.
            DeploymentStatus::Pending,
            DeploymentStatus::Starting => ['state' => 'pending'],

            DeploymentStatus::Hibernated => $this->dispatchWake($deployment),
        };
    }

    /**
     * Atomically transition Hibernated → Starting and dispatch the wake job.
     * Lock prevents two concurrent preview hits from each enqueueing a job.
     *
     * @return array{state: 'pending'}
     */
    private function dispatchWake(BranchDeployment $deployment): array
    {
        $lock = Cache::lock("yak:wake:{$deployment->id}", seconds: 30);

        if (! $lock->get()) {
            return ['state' => 'pending'];
        }

        try {
            $deployment->refresh();

            if ($deployment->status === DeploymentStatus::Hibernated) {
                $deployment->status = DeploymentStatus::Starting;
                $deployment->last_accessed_at = now();
                $deployment->save();

                DeploymentLog::record($deployment, 'info', 'lifecycle', 'Waking from hibernation');

                WakeHibernatedDeploymentJob::dispatch($deployment->id);
            }
        } finally {
            $lock->release();
        }

        return ['state' => 'pending'];
    }

    /**
     * Enqueue a rebuild of a Destroyed row. The lock keeps concurrent preview
     * hits from each enqueueing a DeployBranchJob.
     *
     * @return array{state: 'pending'}
     */
    private function dispatchRedeploy(BranchDeployment $deployment): array
    {
        $lock = Cache::lock("yak:redeploy:{$deployment->id}", seconds: 30);

        // The lock is left to expire, and the conditional update is the real
        // dedup: only the request that moves the row out of Destroyed enqueues,
        // however long the job runs.
        if ($lock->get()) {
            $claimed = BranchDeployment::query()
                ->where('id', $deployment->id)
                ->where('status', DeploymentStatus::Destroyed->value)
                ->update(DeployBranchJob::redeployAttributes($deployment));

            if ($claimed === 1) {
                DeploymentLog::record($deployment, 'info', 'lifecycle', 'Rebuilding destroyed deployment');

                DeployBranchJob::dispatch($deployment->id);
            }
        }

        return ['state' => 'pending'];
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
        return PreviewManifest::fromArray($deployment->preview_manifest ?? $deployment->repository->settings()->previewManifest())->port;
    }
}
