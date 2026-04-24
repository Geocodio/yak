<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeployBranchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deploymentId)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(DeploymentContainerManager $manager): void
    {
        $deployment = BranchDeployment::findOrFail($this->deploymentId);

        try {
            $deployment->status = DeploymentStatus::Starting;
            $deployment->save();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Cloning template snapshot');
            $manager->createFromTemplate($deployment);

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Starting container');
            $manager->start($deployment);

            if ($deployment->current_commit_sha !== null) {
                $manager->applyCheckoutRefresh($deployment, $deployment->current_commit_sha);
            }

            $deployment->status = DeploymentStatus::Running;
            $deployment->last_accessed_at = now();
            $deployment->save();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Deployment ready');
        } catch (\Throwable $e) {
            $deployment->status = DeploymentStatus::Failed;
            $deployment->failure_reason = 'deploy: ' . $e->getMessage();
            $deployment->save();

            DeploymentLog::record($deployment, 'error', 'lifecycle', 'Deploy failed: ' . $e->getMessage());

            throw $e;
        }
    }
}
