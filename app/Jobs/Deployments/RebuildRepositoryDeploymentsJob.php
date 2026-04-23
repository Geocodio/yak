<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebuildRepositoryDeploymentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $repositoryId)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(): void
    {
        $cap = (int) config('yak.deployments.running_cap', 6);

        BranchDeployment::query()
            ->where('repository_id', $this->repositoryId)
            ->whereNotIn('status', [
                DeploymentStatus::Destroyed->value,
                DeploymentStatus::Destroying->value,
            ])
            ->orderBy('last_accessed_at', 'desc')
            ->pluck('id')
            ->values()
            ->each(function ($id, $index) use ($cap) {
                $delaySeconds = max(0, ($index - $cap + 1)) * 60;
                $dispatch = RebuildDeploymentJob::dispatch($id);
                if ($delaySeconds > 0) {
                    $dispatch->delay(now()->addSeconds($delaySeconds));
                }
            });
    }
}
