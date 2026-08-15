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
        // The running set is bounded by running_cap (default 6), so resolving
        // each deployment's effective TTL in PHP is cheap and keeps the
        // per-deployment logic in one place (BranchDeployment::effectiveIdleMinutes).
        BranchDeployment::query()
            ->where('status', DeploymentStatus::Running->value)
            ->get()
            ->filter(function (BranchDeployment $deployment): bool {
                if ($deployment->last_accessed_at === null) {
                    return true;
                }

                return $deployment->last_accessed_at
                    ->lt(now()->subMinutes($deployment->effectiveIdleMinutes()));
            })
            ->each(function (BranchDeployment $deployment) use ($manager): void {
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
