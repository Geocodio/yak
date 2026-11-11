<?php

namespace App\Http\Controllers\Threads;

use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Http\Controllers\Controller;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ContinueTurnController extends Controller
{
    public function __invoke(Request $request, Thread $thread, ThreadTurns $turns, ThreadAccess $access): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        if (! $access->canDrive($request->user(), $thread)) {
            return back()->with('error', 'You cannot continue this thread.');
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return back()->with('error', 'Your computer is working on this thread. Hand it back to Yak before continuing.');
        }

        if (! $thread->isOpen()) {
            return back()->with('error', 'This thread is closed.');
        }

        $lastRun = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();

        if ($lastRun?->getAttribute('turn_state') !== TurnState::StoppedByGuard) {
            return back()->with('error', 'There is nothing to continue.');
        }

        try {
            $turns->start($thread->tasks()->latest('id')->firstOrFail(), 'Continue.', $request->user(), null);
        } catch (ThreadBusy) {
            return back()->with('error', 'This thread is already working. Give it a moment.');
        }

        return back()->with('success', 'Continuing.');
    }
}
