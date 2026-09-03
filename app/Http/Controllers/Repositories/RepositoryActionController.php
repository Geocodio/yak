<?php

namespace App\Http\Controllers\Repositories;

use App\Actions\ApplyPrReviewToOpenPulls;
use App\Actions\DispatchRepositorySetupTask;
use App\Http\Controllers\Controller;
use App\Jobs\Deployments\RebuildRepositoryDeploymentsJob;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;

class RepositoryActionController extends Controller
{
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
