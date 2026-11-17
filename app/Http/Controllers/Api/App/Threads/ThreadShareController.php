<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\UpdateThreadShareRequest;
use App\Http\Resources\ThreadShareData;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\ShortLockWait;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ThreadShareController extends Controller
{
    public function __construct(
        private readonly ThreadAccess $access,
        private readonly ThreadSharing $sharing,
    ) {}

    public function show(Request $request, Thread $thread): JsonResponse
    {
        $this->owner($request, $thread);

        return response()->json(ThreadShareData::for($thread));
    }

    public function update(UpdateThreadShareRequest $request, Thread $thread): JsonResponse
    {
        $owner = $this->owner($request, $thread);

        ShortLockWait::transaction(
            fn () => $this->sharing->apply($thread, $owner, $request->mode(), $request->userIds()),
            fn (): ThreadActionRefused => new ThreadActionRefused('unavailable', 'Yak is busy changing this thread. Try again in a few seconds.', [], 503),
        );

        $current = $this->sharing->current($thread);

        return response()->json(['mode' => $current['mode']->value, 'userIds' => $current['userIds']]);
    }

    /**
     * A hidden thread answers like a missing one (404); a visible thread the
     * person does not own answers 403.
     */
    private function owner(Request $request, Thread $thread): User
    {
        $user = $request->user() ?? abort(401);

        abort_unless(Gate::forUser($user)->allows('view', $thread), 404);
        abort_unless($this->access->canShare($user, $thread), 403);

        return $user;
    }
}
