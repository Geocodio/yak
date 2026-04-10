<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Concerns\VerifiesWebhookSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DroneCIWebhookController extends Controller
{
    use VerifiesWebhookSignature;

    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyWebhookSignature(
            $request,
            (string) config('yak.channels.drone.token'),
            'X-Drone-Signature',
        );

        /** @var string $branch */
        $branch = $request->input('build.source', '');

        // Only act on yak/* branches
        if (! str_starts_with($branch, 'yak/')) {
            return response()->json(['ok' => true, 'skipped' => 'not a yak branch']);
        }

        // Look up task by branch_name
        $task = YakTask::where('branch_name', $branch)->first();

        if (! $task) {
            return response()->json(['ok' => true, 'skipped' => 'no task found for branch']);
        }

        // Verify repo's ci_system is drone
        $repository = Repository::where('slug', $task->repo)->first();

        if (! $repository || $repository->ci_system !== 'drone') {
            return response()->json(['ok' => true, 'skipped' => 'wrong CI system']);
        }

        /** @var string $status */
        $status = $request->input('build.status', 'failure');
        $passed = $status === 'success';

        /** @var string|null $output */
        $output = $passed ? null : $request->input('build.output');

        ProcessCIResultJob::dispatch($task, $passed, $output);

        return response()->json(['ok' => true, 'dispatched' => true]);
    }
}
