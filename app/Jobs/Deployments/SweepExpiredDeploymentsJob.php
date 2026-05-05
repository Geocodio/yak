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

        // last_accessed_at is NULL for deployments that never reached
        // Running (stuck Starting, Failed, abandoned). The original
        // `<` predicate excluded those rows entirely, so they would
        // accumulate forever. Fall back to created_at when null.
        BranchDeployment::query()
            ->whereNotIn('status', [
                DeploymentStatus::Destroyed->value,
                DeploymentStatus::Destroying->value,
            ])
            ->where(function ($q) use ($cutoff) {
                $q->where('last_accessed_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('last_accessed_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->pluck('id')
            ->each(fn ($id) => DestroyDeploymentJob::dispatch($id));

        // Clean up expired share tokens so the dashboard shows accurate state.
        BranchDeployment::query()
            ->whereNotNull('public_share_expires_at')
            ->where('public_share_expires_at', '<', now())
            ->update([
                'public_share_token_hash' => null,
                'public_share_expires_at' => null,
            ]);
    }
}
