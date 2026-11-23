<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\ThreadCloser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ThreadCloseController extends Controller
{
    public function __invoke(Thread $thread, ThreadCloser $closer): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        $closer->close($thread, 'manual');

        // Closing from the sidebar list leaves the person on the page they are reading.
        if (! str_contains(url()->previous(), $thread->public_id)) {
            return back()->with('success', 'Thread closed.');
        }

        $parent = $thread->parent_thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($thread->parent_thread_id);

        return ($parent === null ? redirect()->route('home') : redirect()->route('threads.show', $parent))->with('success', 'Thread closed.');
    }
}
