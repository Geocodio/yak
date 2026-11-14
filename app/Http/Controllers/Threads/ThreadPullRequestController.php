<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\OpenThreadPullRequestRequest;
use App\Jobs\CreatePullRequestJob;
use App\Models\Thread;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Opens the pull request for a thread's latest branch, once the person has
 * acknowledged publishing a private conversation when that applies.
 */
class ThreadPullRequestController extends Controller
{
    public function store(OpenThreadPullRequestRequest $request, Thread $thread): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        ThreadPublishGuard::assertAcknowledged($thread, $request->acknowledgedPrivatePublish());

        // Threads started from the dashboard or by teleport keep their branch on the thread, not on the task.
        $task = $thread->tasks()->whereNotNull('branch_name')->latest('id')->first()
            ?? $thread->tasks()->latest('id')->first();
        $branch = $task?->branch_name ?? $thread->branch;
        abort_if($task === null || blank($branch), 409, 'This thread has no changes to publish yet.');

        if ($task->branch_name === null) {
            $task->update(['branch_name' => $branch]);
        }

        CreatePullRequestJob::dispatch($task);

        return back();
    }
}
