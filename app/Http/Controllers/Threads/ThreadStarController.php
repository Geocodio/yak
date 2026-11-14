<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadStar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ThreadStarController extends Controller
{
    /**
     * Stars the thread for the signed-in person, or removes the star when it is already there.
     */
    public function __invoke(Request $request, Thread $thread): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $attributes = ['user_id' => ($request->user() ?? abort(401))->id, 'thread_id' => $thread->id];
        $star = ThreadStar::query()->where($attributes)->first();

        $star === null ? ThreadStar::query()->create($attributes) : $star->delete();

        return back();
    }
}
