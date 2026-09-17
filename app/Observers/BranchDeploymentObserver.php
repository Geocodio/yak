<?php

namespace App\Observers;

use App\Enums\DeploymentStatus;
use App\Facades\Telemetry;
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

        $original = $deployment->getOriginal('status');
        $from = $original instanceof DeploymentStatus ? $original : DeploymentStatus::tryFrom((string) $original);

        // Duration is how long the previous status lasted, so time-to-ready
        // (starting -> running) and time hibernated fall out of the same event.
        $previousChange = $deployment->getOriginal('updated_at');

        Telemetry::record('deployment.status_changed', [
            'from' => $from?->value,
            'to' => $deployment->status->value,
            'pr_number' => $deployment->pr_number,
            'long_lived' => (bool) $deployment->long_lived,
            'failure_reason' => $deployment->status === DeploymentStatus::Failed
                ? mb_substr((string) $deployment->failure_reason, 0, 200)
                : null,
        ], repo: $deployment->repository->slug, durationMs: $previousChange !== null
            ? max(0, now()->getTimestampMs() - now()->parse($previousChange)->getTimestampMs())
            : null, subject: $deployment);

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
