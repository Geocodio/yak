<?php

namespace App\Http\Controllers\Deployments;

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
