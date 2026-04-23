<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SweepExpiredDeploymentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(): void
    {
        $cutoff = now()->subDays((int) config('yak.deployments.destroy_days'));

        BranchDeployment::query()
            ->whereNotIn('status', [
                DeploymentStatus::Destroyed->value,
                DeploymentStatus::Destroying->value,
            ])
            ->where('last_accessed_at', '<', $cutoff)
            ->pluck('id')
            ->each(fn ($id) => DestroyDeploymentJob::dispatch($id));
    }
}
