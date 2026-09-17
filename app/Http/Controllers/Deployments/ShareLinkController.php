<?php

namespace App\Http\Controllers\Deployments;

use App\Facades\Telemetry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Deployments\StoreShareLinkRequest;
use App\Models\BranchDeployment;
use App\Services\DeploymentShareTokens;
use Illuminate\Http\RedirectResponse;

class ShareLinkController extends Controller
{
    public function store(StoreShareLinkRequest $request, BranchDeployment $deployment, DeploymentShareTokens $tokens): RedirectResponse
    {
        $token = $tokens->mint($deployment, (int) $request->validated('expires_in_days'));

        $deployment->refresh();

        Telemetry::feature('deployment.share_link', ['days' => (int) $request->validated('expires_in_days')], repo: $deployment->repository->slug, source: 'dashboard');

        $url = "https://{$deployment->hostname}/_share/{$token}/";

        return redirect()->route('deployments.show', $deployment)
            ->with('success', 'Share link created.')
            ->with('mintedUrl', $url);
    }

    public function destroy(BranchDeployment $deployment, DeploymentShareTokens $tokens): RedirectResponse
    {
        $tokens->revoke($deployment);

        return redirect()->route('deployments.show', $deployment)->with('success', 'Share link revoked.');
    }
}
