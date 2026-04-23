<?php

namespace App\Http\Controllers\Internal;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        $hostname = (string) $request->query('host');
        $deployment = BranchDeployment::where('hostname', $hostname)->first();
        abort_if($deployment === null, 404);

        $state = match ($deployment->status) {
            DeploymentStatus::Running => 'ready',
            DeploymentStatus::Failed => 'failed',
            DeploymentStatus::Destroyed, DeploymentStatus::Destroying => 'failed',
            default => 'pending',
        };

        return response()->json(array_filter([
            'state' => $state,
            'reason' => $state === 'failed' ? $deployment->failure_reason : null,
        ]));
    }
}
