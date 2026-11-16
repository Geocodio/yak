<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Stacks\StackActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Stop stack: callable from the parent's page or any child's page.
 */
class StackStopController extends Controller
{
    public function __invoke(Thread $thread, StackActions $actions): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread->rootThread());

        $actions->stop($thread);

        return back();
    }
}
