<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
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

        if ($request->header('X-GitHub-Event') !== 'pull_request' || $request->input('action') !== 'closed') {
            return response()->json(['ok' => true, 'skipped' => 'not a pull_request.closed event']);
        }

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
}
