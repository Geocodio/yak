<?php

namespace App\Jobs\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class WatchdogStuckDeploymentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(): void
    {
        $startingCutoff = now()->subMinutes((int) config('yak.deployments.stuck_starting_minutes'));
        $destroyingCutoff = now()->subMinutes((int) config('yak.deployments.stuck_destroying_minutes'));

        // updated_at is the freshest signal that something happened to the
        // row — covers both "DeployBranchJob died after flipping to Starting"
        // and "DestroyDeploymentJob exhausted its 3 tries". Without this
        // watchdog these rows wedge forever and pin their ZFS datasets.
        $stuck = BranchDeployment::query()
            ->where(function ($q) use ($startingCutoff, $destroyingCutoff) {
                $q->where(function ($q) use ($startingCutoff) {
                    $q->where('status', DeploymentStatus::Starting->value)
                        ->where('updated_at', '<', $startingCutoff);
                })->orWhere(function ($q) use ($destroyingCutoff) {
                    $q->where('status', DeploymentStatus::Destroying->value)
                        ->where('updated_at', '<', $destroyingCutoff);
                });
            })
            ->get(['id', 'status', 'container_name', 'updated_at']);

        foreach ($stuck as $deployment) {
            Log::channel('yak')->warning('Watchdog destroying stuck deployment', [
                'deployment_id' => $deployment->id,
                'status' => $deployment->status->value,
                'container' => $deployment->container_name,
                'stuck_since' => $deployment->updated_at?->toIso8601String(),
            ]);

            DestroyDeploymentJob::dispatch($deployment->id);
        }
    }
}
