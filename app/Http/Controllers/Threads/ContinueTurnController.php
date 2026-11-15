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

class ContinueTurnController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadRecovery $recovery, ThreadAccess $access): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        if (! $access->canDrive($request->user(), $thread)) {
            return back()->with('error', 'You cannot continue this thread.');
        }

        try {
            $recovery->continueTurn($thread, $request->user());
        } catch (ThreadActionRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('success', 'Continuing.');
    }
}
