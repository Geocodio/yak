<?php

namespace App\Actions;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ReReviewOutcome;
use App\Enums\TaskMode;
use App\Facades\Telemetry;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;

/**
 * Asks Yak to review the open pull request of a task, in full the first time and
 * incrementally after that. Aborts with 404 when the task has no reviewable
 * pull request and 502 when GitHub cannot be reached.
 */
class RequestReReview
{
    public function __construct(private readonly AppService $github, private readonly EnqueuePrReview $enqueue) {}

    public function __invoke(YakTask $task): ReReviewOutcome
    {
        abort_unless($task->pr_url !== null && $task->pr_url !== '', 404);

        $repo = Repository::where('slug', $task->repo)->first();
        abort_if($repo === null || ! $repo->is_active || ! $repo->settings()->reviewEnabled(), 404);

        // Review tasks carry the PR number in their context; tasks that opened
        // the PR themselves store it on the task row.
        $context = json_decode((string) $task->context, true) ?: [];
        $prNumber = $task->mode === TaskMode::Review
            ? (int) ($context['pr_number'] ?? 0)
            : (int) $task->pr_number;
        abort_if($prNumber <= 0, 404);

        $installationId = (int) config('yak.channels.github.installation_id');
        $pr = $this->github->getPullRequest($installationId, $repo->github_full_name, $prNumber);

        if (! isset($pr['html_url'], $pr['head']['sha'])) {
            abort(502, 'Could not fetch pull request from GitHub.');
        }

        // Re-review only makes sense on open, ready-for-review work.
        if ((bool) ($pr['draft'] ?? false) || ($pr['state'] ?? '') !== 'open') {
            return new ReReviewOutcome($task, false, true, "This pull request isn't open for review (it may be closed, merged, or in draft), so there's nothing to re-review.");
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

        $newTask = $this->enqueue->dispatch($repo, $pr, $scope, $incrementalBase);

        Telemetry::feature('re_request_review', ['scope' => $scope, 'queued' => $newTask !== null], task: $newTask ?? $task, source: 'dashboard');

        if ($newTask !== null) {
            return new ReReviewOutcome($newTask, true, false, $task->mode === TaskMode::Review
                ? 'Re-review requested. A fresh review is now running for this PR -- this page updates live as it progresses.'
                : 'Review requested. Yak is now reviewing this PR -- this page updates live as it progresses.');
        }

        // A review for this head SHA is already pending or running.
        $existing = YakTask::query()
            ->where('mode', TaskMode::Review)
            ->where('external_id', (string) $pr['html_url'])
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        return new ReReviewOutcome($existing ?? $task, false, true, "A re-review for this PR is already in progress. Showing the run that's already underway.");
    }
}
