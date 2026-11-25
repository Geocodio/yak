<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\UpdateThreadTitleRequest;
use App\Models\Thread;
use Illuminate\Http\RedirectResponse;

class ThreadTitleController extends Controller
{
    /**
     * Renames the thread. The request refuses hidden threads (404) and viewers who cannot drive (403).
     */
    public function __invoke(UpdateThreadTitleRequest $request, Thread $thread): RedirectResponse
    {
        $thread->update(['title' => $request->validated('title')]);

        return back();
    }
}
