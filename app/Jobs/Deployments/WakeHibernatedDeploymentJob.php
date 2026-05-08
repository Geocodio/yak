<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WakeHibernatedDeploymentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deploymentId)
    {
        $this->onQueue('yak-deployments');
    }

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    public function handle(DeploymentContainerManager $manager): void
    {
        $deployment = BranchDeployment::with('repository')->findOrFail($this->deploymentId);

        // The wake controller already transitioned us to Starting before
        // dispatching. Re-check defensively so a stale enqueued job (e.g.
        // re-driven after the deployment was destroyed) does nothing.
        if ($deployment->status !== DeploymentStatus::Starting) {
            return;
        }

        try {
            $manager->start($deployment);

            if ($deployment->dirty && $deployment->current_commit_sha !== null) {
                $manager->applyCheckoutRefresh($deployment, $deployment->current_commit_sha);
            }

            $deployment->status = DeploymentStatus::Running;
            $deployment->last_accessed_at = now();
            $deployment->save();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Awake');
        } catch (\Throwable $e) {
            $deployment->status = DeploymentStatus::Failed;
            $deployment->failure_reason = 'wake: ' . $e->getMessage();
            $deployment->save();

            DeploymentLog::record($deployment, 'error', 'lifecycle', 'Wake failed: ' . $e->getMessage());

            throw $e;
        }
    }
}
