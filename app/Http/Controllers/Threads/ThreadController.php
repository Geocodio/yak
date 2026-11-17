<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadRunLogData;
use App\Http\Resources\ThreadShowData;
use App\Models\Thread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ThreadController extends Controller
{
    public function show(Request $request, Thread $thread): Response
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $entryId = $request->query('log');

        return Inertia::render('Threads/Show', [
            ...ThreadShowData::build($thread, $request->user() ?? abort(401)),
            'runs' => ThreadRunLogData::runs($thread),
            'openLog' => is_string($entryId) && ctype_digit($entryId) ? ThreadRunLogData::locate($thread, (int) $entryId) : null,
        ]);
    }
}
