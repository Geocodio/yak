<?php

namespace App\Observers;

use App\Models\BranchDeployment;
use App\Services\DeploymentGitHubSync;
use Illuminate\Support\Facades\Log;

class BranchDeploymentObserver
{
    public function __construct(private readonly DeploymentGitHubSync $sync) {}

    public function updated(BranchDeployment $deployment): void
    {
        if (! $deployment->wasChanged('status')) {
            return;
        }

        try {
            $this->sync->sync($deployment, $deployment->status);
        } catch (\Throwable $e) {
            Log::warning('BranchDeployment GitHub sync failed', [
                'deployment_id' => $deployment->id,
                'new_status' => $deployment->status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
