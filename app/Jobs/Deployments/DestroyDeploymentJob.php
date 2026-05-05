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

        // Forced destroy must work from any status (incl. stuck Starting
        // or Pending), so bypass the state-machine guard and use a raw
        // update — same pattern as RebuildDeploymentJob.
        BranchDeployment::query()->where('id', $deployment->id)
            ->update(['status' => DeploymentStatus::Destroying->value]);
        $deployment->refresh();

        $manager->destroy($deployment);

        BranchDeployment::query()->where('id', $deployment->id)
            ->update(['status' => DeploymentStatus::Destroyed->value]);
    }
}
