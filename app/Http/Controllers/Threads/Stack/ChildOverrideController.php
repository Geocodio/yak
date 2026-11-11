<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Exceptions\StackRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\ChildOverrides;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Take over, split or drop a sub-thread that failed. Needs the right to drive its stack.
 */
class ChildOverrideController extends Controller
{
    public function takeOver(Request $request, Thread $thread, ChildOverrides $overrides): RedirectResponse
    {
        $this->authorizeChild($thread);
        $overrides->takeOver($thread, $this->user($request));

        return back();
    }

    public function split(Request $request, Thread $thread, ChildOverrides $overrides): RedirectResponse
    {
        $this->authorizeChild($thread);

        try {
            $overrides->split($thread, $this->user($request));
        } catch (StackRuleViolation $violation) {
            return back()->withErrors(['stack' => $violation->getMessage()]);
        }

        return back();
    }

    public function drop(Request $request, Thread $thread, ChildOverrides $overrides): RedirectResponse
    {
        $this->authorizeChild($thread);
        $overrides->drop($thread, $this->user($request));

        return back();
    }

    private function authorizeChild(Thread $thread): void
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread) && $thread->isChild(), 404);
        Gate::authorize('drive', $thread);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
