<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadComposerData;
use App\Models\Thread;
use App\Services\Threads\RunawayGuard;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\JsonResponse;

/**
 * "Add steps" for the app: the same raise the web's step-limit route makes, on the turn that runs now.
 */
class ThreadStepLimitController extends Controller
{
    public function __invoke(Thread $thread): JsonResponse
    {
        $run = ThreadTurns::activeTurn($thread);

        if ($run === null || ! RunawayGuard::hasTurnLimit($run)) {
            throw ThreadActionRefused::because(ThreadActionRefused::NO_STEP_LIMIT, 'This thread has no step limit to raise right now.');
        }

        $added = (int) config('yak.max_turns');
        $run->increment('extra_turns', $added);

        return response()->json(['status' => 'raised', 'added' => $added, 'turn' => ThreadComposerData::turn($thread)]);
    }
}
