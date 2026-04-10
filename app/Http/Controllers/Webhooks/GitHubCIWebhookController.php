<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubCIWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.github.webhook_secret'),
            'X-Hub-Signature-256',
        );

        // Only process check_suite.completed events
        if ($request->header('X-GitHub-Event') !== 'check_suite' || $request->input('action') !== 'completed') {
            return response()->json(['ok' => true, 'skipped' => 'not a check_suite.completed event']);
        }

        /** @var string $branch */
        $branch = $request->input('check_suite.head_branch', '');

        // Only act on yak/* branches
        if (! str_starts_with($branch, 'yak/')) {
            return response()->json(['ok' => true, 'skipped' => 'not a yak branch']);
        }

        // Look up task by branch_name
        $task = YakTask::where('branch_name', $branch)->first();

        if (! $task) {
            return response()->json(['ok' => true, 'skipped' => 'no task found for branch']);
        }

        // Verify repo's ci_system is github_actions
        $repository = Repository::where('slug', $task->repo)->first();

        if (! $repository || $repository->ci_system !== 'github_actions') {
            return response()->json(['ok' => true, 'skipped' => 'wrong CI system']);
        }

        /** @var string $conclusion */
        $conclusion = $request->input('check_suite.conclusion', 'failure');
        $passed = $conclusion === 'success';

        /** @var string|null $output */
        $output = $passed ? null : $request->input('check_suite.output.text');

        ProcessCIResultJob::dispatch($task, $passed, $output);

        return response()->json(['ok' => true, 'dispatched' => true]);
    }
}
