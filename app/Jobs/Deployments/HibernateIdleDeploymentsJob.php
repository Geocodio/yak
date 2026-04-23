<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Services\DeploymentContainerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HibernateIdleDeploymentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(DeploymentContainerManager $manager): void
    {
        $cutoff = now()->subMinutes((int) config('yak.deployments.idle_minutes'));

        BranchDeployment::query()
            ->where('status', DeploymentStatus::Running->value)
            ->where(function ($q) use ($cutoff) {
                $q->where('last_accessed_at', '<', $cutoff)
                    ->orWhereNull('last_accessed_at');
            })
            ->get()
            ->each(function (BranchDeployment $deployment) use ($manager) {
                try {
                    $manager->stop($deployment);
                    $deployment->status = DeploymentStatus::Hibernated;
                    $deployment->save();
                } catch (\Throwable $e) {
                    Log::warning('Idle hibernation failed', [
                        'deployment_id' => $deployment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
