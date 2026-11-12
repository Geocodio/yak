<?php

namespace App\Http\Controllers\Threads;

use App\Enums\ThreadBlockReason;
use App\Http\Controllers\Controller;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RestoreRetryController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadAccess $access): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        if (! $access->canDrive($request->user(), $thread)) {
            return back()->with('error', 'You cannot retry the restore on this thread.');
        }

        if (! $thread->isOpen()) {
            return back()->with('error', 'This thread is closed.');
        }

        if ($thread->blocked_reason !== ThreadBlockReason::RestoreFailed) {
            return back()->with('error', 'Nothing needs restoring.');
        }

        if ($thread->isSandboxBusy()) {
            return back()->with('error', 'The restore is already running.');
        }

        RetryThreadRestoreJob::dispatch($thread);

        return back()->with('success', 'Retrying the restore.');
    }
}
