<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

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

        // A PR closed and reopened in quick succession queues this job while
        // the teardown is still running. Wait for the teardown to finish, then
        // bring the destroyed row back to Pending so it can deploy again.
        if ($deployment->status === DeploymentStatus::Destroying) {
            self::dispatch($deployment->id)->delay(now()->addSeconds(30));

            return;
        }

        if ($deployment->status === DeploymentStatus::Destroyed) {
            $this->resetForRedeploy($deployment);
        }

        try {
            $deployment->status = DeploymentStatus::Starting;
            $deployment->save();

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Cloning template snapshot');
            $manager->createFromTemplate($deployment);

            DeploymentLog::record($deployment, 'info', 'lifecycle', 'Starting container');
            $manager->start($deployment, $deployment->current_commit_sha);

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

    /**
     * Destroyed is a final state, so the reset bypasses the state machine with
     * a raw update -- same pattern as DestroyDeploymentJob. The old container
     * is gone, so the row gets a fresh container name and the current template.
     */
    private function resetForRedeploy(BranchDeployment $deployment): void
    {
        BranchDeployment::query()->where('id', $deployment->id)->update([
            'status' => DeploymentStatus::Pending->value,
            'container_name' => 'deploy-' . Str::lower((string) Str::ulid()),
            'template_version' => max(1, $deployment->repository->current_template_version ?? 0),
            'dirty' => false,
            'failure_reason' => null,
            'last_accessed_at' => null,
        ]);

        $deployment->refresh();
    }
}
