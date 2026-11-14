<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\ThreadDeleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class ThreadDestroyController extends Controller
{
    public function __invoke(Thread $thread, ThreadDeleter $deleter): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        $parent = $thread->parent_thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($thread->parent_thread_id);

        try {
            $deleter->delete($thread);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // Deleting from the sidebar list leaves the person on the page they are reading.
        if (! str_contains(url()->previous(), $thread->public_id)) {
            return back()->with('success', 'Thread deleted.');
        }

        return ($parent === null ? redirect()->route('home') : redirect()->route('threads.show', $parent))->with('success', 'Thread deleted.');
    }
}
