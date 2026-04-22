<?php

namespace App\Channels\GitHub;

use App\Actions\EnqueuePrReview;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCIResultJob;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        if ($event !== 'pull_request') {
            return response()->json(['ok' => true, 'skipped' => "unhandled event: {$event}"]);
        }

        $action = $request->input('action');

        if ($action === 'closed') {
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
