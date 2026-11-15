<?php

namespace App\Http\Controllers\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\OpenThreadPullRequestRequest;
use App\Models\Thread;
use App\Services\Threads\PullRequests\OpenPullRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Opens the pull request for a thread's latest branch, once the person has
 * acknowledged publishing a private conversation when that applies.
 */
class ThreadPullRequestController extends Controller
{
    public function store(OpenThreadPullRequestRequest $request, Thread $thread, OpenPullRequest $openPullRequest): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        try {
            $openPullRequest->open($thread, $request->user() ?? abort(401), $request->acknowledgedPrivatePublish());
        } catch (ThreadActionRefused $refused) {
            abort(409, $refused->getMessage());
        }

        return back();
    }
}
