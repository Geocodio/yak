<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\Artifact;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ThreadSharedFileController extends Controller
{
    /**
     * Opens or closes one shared file to every signed-in user. Whoever may drive the thread decides:
     * its owner on a private thread, any teammate on a team thread.
     */
    public function __invoke(Request $request, Thread $thread, string $sharedFile): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $file = Artifact::query()
            ->shared()
            ->whereIn('yak_task_id', $thread->tasks()->select('id'))
            ->where('public_id', $sharedFile)
            ->first();

        abort_if($file === null, 404);
        Gate::authorize('drive', $thread);

        $shared = $request->validate(['shared' => ['required', 'boolean']])['shared'];

        $file->update([
            'shared_with_team' => $shared,
            'shared_at' => $shared ? now() : null,
        ]);

        return response()->json(['id' => $file->public_id, 'sharedWithTeam' => $file->shared_with_team]);
    }
}
