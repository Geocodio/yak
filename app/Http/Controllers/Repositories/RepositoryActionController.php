<?php

namespace App\Http\Controllers\Repositories;

use App\Actions\ApplyPrReviewToOpenPulls;
use App\Actions\DispatchRepositorySetupTask;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\RiskProfileRequest;
use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Models\Repository;
use App\Services\RepositoryRiskProfiles;
use Illuminate\Http\RedirectResponse;

class RepositoryActionController extends Controller
{
    public function riskProfile(RiskProfileRequest $request, Repository $repository, RepositoryRiskProfiles $profiles): RedirectResponse
    {
        abort_unless($repository->is_active, 422, 'Repository is inactive.');
        $data = $request->validated();
        $user = $request->user();
        abort_if($user === null, 403);
        try {
            if ($data['action'] === 'generate') {
                $task = $profiles->generate($repository, 'dashboard');

                return redirect()->route('tasks.show', $task)->with('success', 'Risk profile research queued. Review the draft in repository settings when it completes.');
            }
            if ($data['action'] === 'import') {
                $content = json_decode($data['content'], true, 64, JSON_THROW_ON_ERROR);
                $profiles->draft($repository->slug, (string) ($content['source_sha'] ?? ''), $data['content']);
            } else {
                $profiles->approve($repository->slug, $data['version'], 'user:' . $user->id . ' (' . $user->name . ')');
            }
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('repos.edit', $repository)->with('error', 'Could not save the risk profile. Check the draft format and version, then retry.');
        }

        return redirect()->route('repos.edit', $repository)->with('success', $data['action'] === 'approve' ? 'Risk profile approved.' : 'Draft saved. Review it before approving.');
    }

    public function toggleActive(Repository $repository): RedirectResponse
    {
        $repository->update(['is_active' => ! $repository->is_active]);

        return redirect()->route('repos.edit', $repository)
            ->with('success', $repository->is_active ? 'Repository activated.' : 'Repository deactivated.');
    }

    public function rerunSetup(Repository $repository, DispatchRepositorySetupTask $dispatchSetup): RedirectResponse
    {
        $task = $dispatchSetup($repository);

        return redirect()->route('tasks.show', $task)->with('success', 'Setup task dispatched.');
    }

    public function reviewOpenPrs(Repository $repository, ApplyPrReviewToOpenPulls $applyPrReview): RedirectResponse
    {
        $count = $applyPrReview($repository);

        return redirect()->route('repos.edit', $repository)->with('success', "Enqueued review for {$count} open PRs.");
    }

    public function rebuildDeployments(Repository $repository): RedirectResponse
    {
        RebuildRepositoryDeploymentsJob::dispatch($repository->id);

        return redirect()->route('repos.edit', $repository)->with('success', 'Bulk rebuild queued for all active deployments.');
    }
}
