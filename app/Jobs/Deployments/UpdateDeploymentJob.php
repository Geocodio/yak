<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $deploymentId, public string $commitSha)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(DeploymentContainerManager $manager): void
    {
        $deployment = BranchDeployment::findOrFail($this->deploymentId);

        if ($deployment->status === DeploymentStatus::Running) {
            $manager->applyCheckoutRefresh($deployment, $this->commitSha);

            return;
        }

        if ($deployment->status === DeploymentStatus::Hibernated) {
            $deployment->update([
                'dirty' => true,
                'current_commit_sha' => $this->commitSha,
            ]);

            return;
        }

        // Pending, Starting, Failed, Destroying, Destroyed: no-op.
    }
}
