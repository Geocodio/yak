<?php

namespace App\Channels\GitHub;

use App\Actions\EnqueuePrReview;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\Deployments\DeployBranchJob;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\UpdateDeploymentJob;
use App\Jobs\ProcessCIResultJob;
use App\Models\BranchDeployment;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\BranchDeploymentProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request, AppService $github): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.github.webhook_secret'),
            'X-Hub-Signature-256',
        );

        $event = (string) $request->header('X-GitHub-Event', '');

        if ($event === 'check_suite') {
            return $this->handleCheckSuite($request, $github);
        }

        if ($event === 'push') {
            return $this->handleDeploymentPush($request);
        }

        if ($event === 'delete') {
            return $this->handleDeploymentDelete($request);
        }

        if ($event !== 'pull_request') {
            return response()->json(['ok' => true, 'skipped' => "unhandled event: {$event}"]);
        }

        $action = $request->input('action');

        if ($action === 'closed') {
            $this->maybeDispatchDeployment($request, 'closed');

            return $this->handleClosed($request);
        }

        if ($action === 'labeled') {
            $labelName = (string) $request->input('label.name', '');
            $triggerLabel = (string) config('yak.pr_review.trigger_label', 'yak-review');

            if ($labelName !== $triggerLabel) {
                return response()->json(['ok' => true, 'skipped' => "unhandled label: {$labelName}"]);
            }

            return $this->handleReviewTrigger($request, 'labeled');
        }

        if (in_array($action, ['opened', 'ready_for_review', 'reopened', 'synchronize'], true)) {
            $this->maybeDispatchDeployment($request, $action);

            return $this->handleReviewTrigger($request, $action);
        }

        return response()->json(['ok' => true, 'skipped' => "unhandled action: {$action}"]);
    }

    private function handleCheckSuite(Request $request, AppService $github): JsonResponse
    {
        if ($request->input('action') !== 'completed') {
            return response()->json(['ok' => true, 'skipped' => 'not a check_suite.completed event']);
        }

        /** @var string $branch */
        $branch = $request->input('check_suite.head_branch', '');

        if (! str_starts_with($branch, 'yak/')) {
            return response()->json(['ok' => true, 'skipped' => 'not a yak branch']);
        }

        $task = YakTask::where('branch_name', $branch)->first();

        if (! $task) {
            return response()->json(['ok' => true, 'skipped' => 'no task found for branch']);
        }

        $repository = Repository::where('slug', $task->repo)->first();

        if (! $repository || $repository->ci_system !== 'github_actions') {
            return response()->json(['ok' => true, 'skipped' => 'wrong CI system']);
        }

        /** @var string $conclusion */
        $conclusion = $request->input('check_suite.conclusion', 'failure');
        $passed = $conclusion === 'success';

        $output = null;
        if (! $passed) {
            $installationId = (int) config('yak.channels.github.installation_id');
            $commitSha = (string) $request->input('check_suite.head_sha', '');

            if ($installationId > 0 && $commitSha !== '') {
                $output = $github->getFailedCheckRunOutput($installationId, $task->repo, $commitSha);
            }
        }

        ProcessCIResultJob::dispatch($task, $passed, $output);

        return response()->json(['ok' => true, 'dispatched' => true]);
    }

    private function handleClosed(Request $request): JsonResponse
    {
        /** @var string $prUrl */
        $prUrl = $request->input('pull_request.html_url', '');

        $task = YakTask::where('pr_url', $prUrl)->first();
        $merged = (bool) $request->input('pull_request.merged', false);

        if ($task) {
            if ($merged) {
                $task->update(['pr_merged_at' => $request->input('pull_request.merged_at', now())]);
            } else {
                $task->update(['pr_closed_at' => $request->input('pull_request.closed_at', now())]);
            }
        }

        $prReviews = PrReview::where('pr_url', $prUrl)->get();

        foreach ($prReviews as $pr) {
            $updates = ['pr_closed_at' => $request->input('pull_request.closed_at', now())];
            if ($merged) {
                $updates['pr_merged_at'] = $request->input('pull_request.merged_at', now());
            }
            $pr->update($updates);
        }

        if (! $task && $prReviews->isEmpty()) {
            return response()->json(['ok' => true, 'skipped' => 'no task found for PR']);
        }

        return response()->json(['ok' => true, 'updated' => true]);
    }

    private function maybeDispatchDeployment(Request $request, string $action): void
    {
        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo === null || ! $repo->deployments_enabled) {
            return;
        }

        if (in_array($action, ['opened', 'reopened'], true)) {
            if ((int) ($repo->current_template_version ?? 0) < 1) {
                // Repo hasn't been set up under the versioned-template regime yet.
                // Log and skip; operator needs to re-run SetupYakJob.
                Log::warning('Skipping deployment: repo has no versioned template', [
                    'repository_id' => $repo->id,
                    'repository_slug' => $repo->slug,
                    'branch' => $request->input('pull_request.head.ref'),
                ]);

                return;
            }

            $branch = (string) $request->input('pull_request.head.ref');
            $sha = (string) $request->input('pull_request.head.sha');
            $prNumber = (int) $request->input('pull_request.number');

            $deployment = app(BranchDeploymentProvisioner::class)->provision($repo, $branch);
            $deployment->update([
                'pr_number' => $prNumber,
                'pr_state' => 'open',
                'current_commit_sha' => $sha,
            ]);
            DeployBranchJob::dispatch($deployment->id);

            return;
        }

        if ($action === 'synchronize') {
            $branch = (string) $request->input('pull_request.head.ref');
            $sha = (string) $request->input('pull_request.head.sha');

            $deployment = BranchDeployment::where('repository_id', $repo->id)
                ->where('branch_name', $branch)
                ->whereNotIn('status', ['destroying', 'destroyed'])
                ->first();

            if ($deployment !== null) {
                UpdateDeploymentJob::dispatch($deployment->id, $sha);
            }

            return;
        }

        if ($action === 'closed') {
            $branch = (string) $request->input('pull_request.head.ref');
            $merged = (bool) $request->input('pull_request.merged', false);

            $deployment = BranchDeployment::where('repository_id', $repo->id)
                ->where('branch_name', $branch)
                ->whereNotIn('status', ['destroying', 'destroyed'])
                ->first();

            if ($deployment !== null) {
                $deployment->update(['pr_state' => $merged ? 'merged' : 'closed']);
                DestroyDeploymentJob::dispatch($deployment->id);
            }
        }
    }

    private function handleDeploymentPush(Request $request): JsonResponse
    {
        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo === null || ! $repo->deployments_enabled) {
            return response()->json(['ok' => true, 'skipped' => 'deployments disabled or repo not found']);
        }

        $ref = (string) $request->input('ref', '');

        if (! str_starts_with($ref, 'refs/heads/')) {
            return response()->json(['ok' => true, 'skipped' => 'not a branch push']);
        }

        $branch = substr($ref, strlen('refs/heads/'));
        $sha = (string) $request->input('after');

        $deployment = BranchDeployment::where('repository_id', $repo->id)
            ->where('branch_name', $branch)
            ->whereNotIn('status', ['destroying', 'destroyed'])
            ->first();

        if ($deployment !== null) {
            UpdateDeploymentJob::dispatch($deployment->id, $sha);
        }

        return response()->json(['ok' => true]);
    }

    private function handleDeploymentDelete(Request $request): JsonResponse
    {
        if ((string) $request->input('ref_type') !== 'branch') {
            return response()->json(['ok' => true, 'skipped' => 'not a branch delete']);
        }

        $repo = $this->resolveRepositoryFromPayload($request);

        if ($repo === null || ! $repo->deployments_enabled) {
            return response()->json(['ok' => true, 'skipped' => 'deployments disabled or repo not found']);
        }

        $branch = (string) $request->input('ref');

        $deployment = BranchDeployment::where('repository_id', $repo->id)
            ->where('branch_name', $branch)
            ->whereNotIn('status', ['destroying', 'destroyed'])
            ->first();

        if ($deployment !== null) {
            DestroyDeploymentJob::dispatch($deployment->id);
        }

        return response()->json(['ok' => true]);
    }

    private function resolveRepositoryFromPayload(Request $request): ?Repository
    {
        $fullName = (string) $request->input('repository.full_name');

        return $fullName === '' ? null : Repository::where('slug', $fullName)->first();
    }

    private function handleReviewTrigger(Request $request, string $action): JsonResponse
    {
        $pr = (array) $request->input('pull_request', []);
        $repoFullName = (string) $request->input('repository.full_name', '');

        $repo = Repository::where('slug', $repoFullName)->first();

        if ($repo === null || ! $repo->is_active) {
            return response()->json(['ok' => true, 'skipped' => 'repo not registered or inactive']);
        }

        if (! $repo->pr_review_enabled) {
            return response()->json(['ok' => true, 'skipped' => 'pr review disabled on repo']);
        }

        if ((bool) ($pr['draft'] ?? false)) {
            return response()->json(['ok' => true, 'skipped' => 'draft PR']);
        }

        if ((string) ($pr['user']['login'] ?? '') === app(AppService::class)->appBotLogin()) {
            return response()->json(['ok' => true, 'skipped' => 'yak-authored PR']);
        }

        $scope = $action === 'synchronize' ? 'incremental' : 'full';
        $incrementalBase = null;

        if ($scope === 'incremental') {
            $prior = PrReview::where('pr_url', (string) $pr['html_url'])
                ->whereNull('dismissed_at')
                ->orderByDesc('submitted_at')
                ->first();

            if ($prior === null) {
                $scope = 'full';
            } else {
                $incrementalBase = $prior->commit_sha_reviewed;
            }
        }

        $task = app(EnqueuePrReview::class)->dispatch($repo, $pr, $scope, $incrementalBase);

        if ($task === null) {
            return response()->json(['ok' => true, 'skipped' => 'duplicate']);
        }

        return response()->json(['ok' => true, 'enqueued' => $task->id]);
    }
}
