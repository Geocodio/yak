<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadComposerData;
use App\Http\Resources\ThreadMessagesData;
use App\Http\Resources\ThreadNoticeData;
use App\Http\Resources\ThreadPageData;
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

        $viewer = $request->user();

        return Inertia::render('Threads/Show', array_merge(ThreadPageData::build($thread, $viewer), [
            'messages' => ThreadMessagesData::for($thread),
            'composer' => ThreadComposerData::for($thread, $viewer),
            'notice' => ThreadNoticeData::for($thread, $viewer),
        ]));
    }
}
