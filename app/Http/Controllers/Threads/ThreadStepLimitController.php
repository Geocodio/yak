<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\RunawayGuard;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\RedirectResponse;

/**
 * "Add 300 steps": raises the step limit of the turn that runs now, so Yak does not pause it.
 */
class ThreadStepLimitController extends Controller
{
    public function __invoke(Thread $thread): RedirectResponse
    {
        $run = ThreadTurns::activeTurn($thread);

        if ($run === null || ! RunawayGuard::hasTurnLimit($run)) {
            return back()->with('error', 'This thread has no step limit to raise right now.');
        }

        $run->increment('extra_turns', (int) config('yak.max_turns'));

        return back()->with('success', 'Added ' . (int) config('yak.max_turns') . ' steps.');
    }
}
