<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Exceptions\StackRuleViolation;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\StackActions;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Take over, split or drop a sub-thread that failed. Needs the right to drive its stack.
 */
class ChildOverrideController extends Controller
{
    public function takeOver(Request $request, Thread $thread, StackActions $actions): RedirectResponse
    {
        return $this->run($request, $thread, fn (User $user) => $actions->takeOver($thread, $user));
    }

    public function split(Request $request, Thread $thread, StackActions $actions): RedirectResponse
    {
        return $this->run($request, $thread, fn (User $user) => $actions->split($thread, $user));
    }

    public function drop(Request $request, Thread $thread, StackActions $actions): RedirectResponse
    {
        return $this->run($request, $thread, fn (User $user) => $actions->drop($thread, $user));
    }

    /**
     * @param  Closure(User): void  $action
     */
    private function run(Request $request, Thread $thread, Closure $action): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread) && $thread->isChild(), 404);
        Gate::authorize('drive', $thread);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $action($user);
        } catch (ThreadActionRefused|StackRuleViolation $refusal) {
            return back()->withErrors(['stack' => $refusal->getMessage()]);
        }

        return back();
    }
}
