<?php

namespace App\Http\Controllers\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RestoreRetryController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadAccess $access, ThreadRecovery $recovery): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        if (! $access->canDrive($request->user(), $thread)) {
            return back()->with('error', 'You cannot retry the restore on this thread.');
        }

        try {
            if (! $recovery->retryRestore($thread)) {
                return back()->with('error', 'The restore is already running.');
            }
        } catch (ThreadActionRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('success', 'Retrying the restore.');
    }
}
