<?php

namespace App\Jobs\Deployments;

use App\Channels\Slack\PreviewRepairMessenger;
use App\Enums\DeploymentStatus;
use App\Models\PreviewRepair;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes a repair whose pull request merged: rebuilds every attached preview
 * that is still failing and tells its author.
 *
 * Those branches do not change `.yak/preview.yml`, so the rebuild runs the
 * default branch's recipe, which now carries the fix.
 */
class ResolvePreviewRepairJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $previewRepairId)
    {
        $this->onQueue('yak-deployments');
    }

    public function handle(PreviewRepairMessenger $messenger): void
    {
        $previewRepair = PreviewRepair::find($this->previewRepairId);

        if ($previewRepair === null) {
            return;
        }

        $isClaimed = PreviewRepair::query()
            ->whereKey($previewRepair->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]) === 1;

        if (! $isClaimed) {
            return;
        }

        $failedDeploymentIds = array_values(array_map(
            intval(...),
            $previewRepair->deployments()->where('status', DeploymentStatus::Failed->value)->pluck('branch_deployments.id')->all(),
        ));

        foreach ($failedDeploymentIds as $deploymentId) {
            RebuildDeploymentJob::dispatch($deploymentId);
        }

        if ($failedDeploymentIds !== []) {
            $messenger->rebuilt($previewRepair->refresh(), $failedDeploymentIds);
        }
    }
}
