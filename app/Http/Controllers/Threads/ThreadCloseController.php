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

        return back()->with('success', 'Thread closed.');
    }
}
