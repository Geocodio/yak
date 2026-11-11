<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\Stack\UpdateStackLimitRequest;
use App\Models\Thread;
use App\Services\Stacks\ChildAwakeLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The stepper in the Stack panel: how many sub-threads may be awake at once.
 */
class StackLimitController extends Controller
{
    public function __invoke(UpdateStackLimitRequest $request, Thread $thread, ChildAwakeLimit $limit): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        $limit->set($thread->rootThread(), (int) $request->validated('limit'));

        return back();
    }
}
