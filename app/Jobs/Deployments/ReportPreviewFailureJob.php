<?php

namespace App\Jobs\Deployments;

use App\Models\BranchDeployment;
use App\Services\PreviewRepair\PreviewFailureReporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hands a failed preview to the reporter off the deployment path, so a
 * problem in the reporter never affects the deployment itself.
 */
class ReportPreviewFailureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $deploymentId,
        public ?string $exceptionMessage = null,
        public ?string $commitSha = null,
    ) {
        $this->onQueue('yak-deployments');
    }

    public function handle(PreviewFailureReporter $reporter): void
    {
        $deployment = BranchDeployment::with('repository')->find($this->deploymentId);

        if ($deployment !== null) {
            $reporter->report($deployment, $this->exceptionMessage, $this->commitSha);
        }
    }
}
