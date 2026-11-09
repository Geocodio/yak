<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadView;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ThreadSeenController extends Controller
{
    public function store(Request $request, Thread $thread): Response
    {
        ThreadView::query()->updateOrCreate(
            ['user_id' => ($request->user() ?? abort(401))->id, 'thread_id' => $thread->id],
            ['last_seen_at' => now()],
        );

        return response()->noContent();
    }
}
