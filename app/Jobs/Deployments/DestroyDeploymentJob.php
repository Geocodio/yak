<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DestroyDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $deploymentId)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(DeploymentContainerManager $manager): void
    {
        $deployment = BranchDeployment::findOrFail($this->deploymentId);

        if ($deployment->status === DeploymentStatus::Destroyed) {
            return;
        }

        $deployment->status = DeploymentStatus::Destroying;
        $deployment->save();

        $manager->destroy($deployment);

        $deployment->status = DeploymentStatus::Destroyed;
        $deployment->save();
    }
}
