<?php

namespace App\Http\Controllers\Tasks;

use App\Actions\EnqueuePrReview;
use App\Channels\GitHub\AppService;
use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\RedirectResponse;

class RequestReReviewController extends Controller
{
    public function __invoke(YakTask $task, AppService $github, EnqueuePrReview $enqueue): RedirectResponse
    {
        abort_unless($task->mode === TaskMode::Review, 404);
        abort_unless($task->pr_url !== null && $task->pr_url !== '', 404);

        $repo = Repository::where('slug', $task->repo)->first();
        abort_if($repo === null || ! $repo->is_active || ! $repo->pr_review_enabled, 404);

        $context = json_decode((string) $task->context, true) ?: [];
        $prNumber = (int) ($context['pr_number'] ?? 0);
        abort_if($prNumber <= 0, 404);

        $installationId = (int) config('yak.channels.github.installation_id');
        $pr = $github->getPullRequest($installationId, $repo->github_full_name, $prNumber);

        if (! isset($pr['html_url'], $pr['head']['sha'])) {
            abort(502, 'Could not fetch pull request from GitHub.');
        }

        // Bail gracefully on closed/merged/draft PRs — re-review only makes
        // sense on open, ready-for-review work. Sending the user back to the
        // original task page is friendlier than 4xx for a stale link click.
        if ((bool) ($pr['draft'] ?? false) || ($pr['state'] ?? '') !== 'open') {
            return redirect()->route('tasks.show', $task)->with('error', "This pull request isn't open for review (it may be closed, merged, or in draft), so there's nothing to re-review.");
        }

        $scope = 'incremental';
        $incrementalBase = null;
        $prior = PrReview::where('pr_url', (string) $pr['html_url'])
            ->whereNull('dismissed_at')
            ->orderByDesc('submitted_at')
            ->first();

        if ($prior === null) {
            $scope = 'full';
        } else {
            $incrementalBase = $prior->commit_sha_reviewed;
        }

        $newTask = $enqueue->dispatch($repo, $pr, $scope, $incrementalBase);

        if ($newTask !== null) {
            return redirect()->route('tasks.show', $newTask)->with('success', 'Re-review requested. A fresh review is now running for this PR -- this page updates live as it progresses.');
        }

        // Duplicate — a review for this head SHA is already pending/running.
        // Redirect to that task so the user sees progress instead of a no-op.
        $existing = YakTask::query()
            ->where('mode', TaskMode::Review)
            ->where('external_id', (string) $pr['html_url'])
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        return redirect()->route('tasks.show', $existing ?? $task)->with('error', "A re-review for this PR is already in progress. Showing the run that's already underway.");
    }
}
