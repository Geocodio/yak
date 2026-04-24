<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebuildDeploymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $deploymentId)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(DeploymentContainerManager $manager): void
    {
        $deployment = BranchDeployment::with('repository')->findOrFail($this->deploymentId);

        try {
            BranchDeployment::query()->where('id', $deployment->id)->update(['status' => DeploymentStatus::Destroying->value]);
            $deployment->refresh();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Destroying container for rebuild');
            $manager->destroy($deployment);

            $deployment->template_version = (int) $deployment->repository->current_template_version;
            BranchDeployment::query()->where('id', $deployment->id)->update([
                'status' => DeploymentStatus::Starting->value,
                'template_version' => $deployment->template_version,
            ]);
            $deployment->refresh();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Cloning template snapshot v' . $deployment->template_version);
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
            BranchDeployment::query()->where('id', $deployment->id)->update([
                'status' => DeploymentStatus::Failed->value,
                'failure_reason' => 'rebuild: ' . $e->getMessage(),
            ]);
            DeploymentLog::record($deployment, 'error', 'lifecycle', 'Rebuild failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
