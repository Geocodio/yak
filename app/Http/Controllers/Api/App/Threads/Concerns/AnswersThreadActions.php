<?php

namespace App\Http\Controllers\Api\App\Threads\Concerns;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Threads\Concerns\GuardsThreadActions;
use App\Http\Resources\AppThreadData;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The answers of the app's thread action routes: the status word the matching task route answers, with the refreshed thread show beside it.
 */
trait AnswersThreadActions
{
    use GuardsThreadActions;

    private function nothingTo(string $verb): never
    {
        throw new ThreadActionRefused("nothing_to_{$verb}", "This thread has nothing to {$verb}.", [], 422);
    }

    private function answer(Request $request, Thread $thread, string $status): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user() ?? abort(401);

        return response()->json(['status' => $status] + AppThreadData::for($thread->fresh() ?? $thread, $viewer));
    }
}
