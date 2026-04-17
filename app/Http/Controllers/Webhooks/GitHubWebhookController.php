<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\EnqueuePrReview;
use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.github.webhook_secret'),
            'X-Hub-Signature-256',
        );

        if ($request->header('X-GitHub-Event') !== 'pull_request') {
            return response()->json(['ok' => true, 'skipped' => 'not a pull_request event']);
        }

        $action = $request->input('action');

        if ($action === 'closed') {
            return $this->handleClosed($request);
        }

        if (in_array($action, ['opened', 'ready_for_review', 'reopened', 'synchronize'], true)) {
            return $this->handleReviewTrigger($request, $action);
        }

        return response()->json(['ok' => true, 'skipped' => "unhandled action: {$action}"]);
    }

    private function handleClosed(Request $request): JsonResponse
    {
        /** @var string $prUrl */
        $prUrl = $request->input('pull_request.html_url', '');

        $task = YakTask::where('pr_url', $prUrl)->first();

        if (! $task) {
            return response()->json(['ok' => true, 'skipped' => 'no task found for PR']);
        }

        $merged = (bool) $request->input('pull_request.merged', false);

        if ($merged) {
            $task->update(['pr_merged_at' => $request->input('pull_request.merged_at', now())]);
        } else {
            $task->update(['pr_closed_at' => $request->input('pull_request.closed_at', now())]);
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

        if ((string) ($pr['user']['login'] ?? '') === (string) config('yak.channels.github.app_bot_login')) {
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
