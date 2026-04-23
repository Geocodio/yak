<?php

namespace App\Http\Controllers\Internal;

use App\Models\BranchDeployment;
use App\Services\DeploymentWaker;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeploymentWakeController
{
    public function __construct(private readonly DeploymentWaker $waker) {}

    public function __invoke(Request $request): Response
    {
        $hostname = $request->header('X-Forwarded-Host') ?? $request->getHost();

        $deployment = BranchDeployment::where('hostname', $hostname)->first();
        if ($deployment === null) {
            return response('Unknown preview hostname.', 404);
        }

        $deployment->update(['last_accessed_at' => now()]);

        $outcome = $this->waker->ensureReady($deployment);

        return match ($outcome['state']) {
            'ready' => response('', 200)
                ->header('X-Upstream-Host', $outcome['host'])
                ->header('X-Upstream-Port', (string) $outcome['port'])
                ->header('X-Yak-Deployment-Id', (string) $deployment->id),

            'pending' => response()->view('deployments.cold_boot_shim', [
                'deployment' => $deployment,
            ], 425),

            'failed' => response()->view('deployments.cold_boot_shim', [
                'deployment' => $deployment,
                'failed' => true,
                'reason' => $outcome['reason'] ?? 'Unknown error',
            ], 502),
        };
    }
}
