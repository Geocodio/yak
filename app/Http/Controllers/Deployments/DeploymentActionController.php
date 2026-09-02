<?php

namespace App\Http\Controllers\Deployments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deployments\UpdateHibernationRequest;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Models\BranchDeployment;
use App\Support\HibernationDuration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class DeploymentActionController extends Controller
{
    public function updateHibernation(UpdateHibernationRequest $request, BranchDeployment $deployment): RedirectResponse
    {
        $validated = $request->validated();

        $longLived = (bool) $validated['long_lived'];
        $timeoutInput = trim((string) ($validated['timeout'] ?? ''));
        $wasLongLived = (bool) $deployment->long_lived;

        $minutes = null;

        if ($longLived && $timeoutInput !== '') {
            $minutes = HibernationDuration::toMinutes($timeoutInput);

            if ($minutes === null) {
                throw ValidationException::withMessages([
                    'timeout' => 'Enter a duration like 3d, 12h, or 2w.',
                ]);
            }
        } elseif ($longLived) {
            $minutes = $deployment->idle_timeout_minutes;
        }

        $deployment->long_lived = $longLived;
        $deployment->idle_timeout_minutes = $longLived ? $minutes : null;
        $deployment->save();

        $message = match (true) {
            $longLived && ! $wasLongLived => 'Marked as long-lived.',
            ! $longLived && $wasLongLived => 'Reverted to standard hibernation.',
            default => 'Hibernation timeout updated.',
        };

        return redirect()->route('deployments.show', $deployment)->with('success', $message);
    }

    public function rebuild(BranchDeployment $deployment): RedirectResponse
    {
        RebuildDeploymentJob::dispatch($deployment->id);

        return redirect()->route('deployments.show', $deployment)->with('success', 'Rebuild queued.');
    }

    public function destroy(BranchDeployment $deployment): RedirectResponse
    {
        DestroyDeploymentJob::dispatch($deployment->id);

        return redirect()->route('deployments.show', $deployment)->with('success', 'Destroy queued.');
    }
}
