<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadView;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ThreadSeenController extends Controller
{
    public function __invoke(Request $request, Thread $thread): Response
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        ThreadView::query()->updateOrCreate(
            ['user_id' => ($request->user() ?? abort(401))->id, 'thread_id' => $thread->id],
            ['last_seen_at' => now()],
        );

        return response()->noContent();
    }
}
