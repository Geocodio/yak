<?php

namespace App\Http\Controllers\Threads;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\ThreadCiChecks;
use App\Services\Threads\ThreadTasks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Re-run failed checks": asks GitHub to run the failed jobs of the branch's newest commit again.
 */
class ThreadCiRerunController extends Controller
{
    public function __invoke(Thread $thread, GitHubAppService $gitHub): RedirectResponse
    {
        $task = ThreadTasks::head($thread);
        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $thread->isOpen() || $task?->branch_name === null || $installationId === 0) {
            return back()->with('error', 'This thread has no checks to re-run.');
        }

        $slug = Repository::githubNameFor($task->repo);

        try {
            $failedRuns = array_filter(
                $gitHub->latestCommitWorkflowRunsForBranch($installationId, $slug, $task->branch_name, isTimeBounded: true),
                fn (array $run): bool => ! GitHubAppService::isUnfinishedWorkflowRun($run)
                    && ! in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true),
            );

            if ($failedRuns === []) {
                return back()->with('error', 'There are no failed checks to re-run.');
            }

            foreach ($failedRuns as $run) {
                $response = $gitHub->rerunFailedJobsResponse($installationId, $slug, $run['id'], isTimeBounded: true);

                if (! $response->successful()) {
                    return back()->with('error', (string) ($response->json('message') ?: 'GitHub could not re-run the failed checks.'));
                }
            }
        } catch (Throwable $unreachable) {
            Log::channel('yak')->warning('Could not re-run the failed checks of a thread', ['thread_id' => $thread->id, 'error' => $unreachable->getMessage()]);

            return back()->with('error', 'GitHub could not be reached. Try again in a moment.');
        }

        ThreadCiChecks::forgetBranch($thread);

        return back()->with('success', 'Re-running the failed checks.');
    }
}
