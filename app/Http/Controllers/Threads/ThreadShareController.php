<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\UpdateThreadShareRequest;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadSecretFiles;
use App\Services\Threads\ThreadSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreadShareController extends Controller
{
    public function __construct(
        private readonly ThreadAccess $access,
        private readonly ThreadSharing $sharing,
    ) {}

    public function show(Request $request, Thread $thread, ThreadSecretFiles $secretFiles): JsonResponse
    {
        $owner = $this->owner($request, $thread);
        $current = $this->sharing->current($thread);
        $secrets = $secretFiles->for($thread->rootThread());

        return response()->json([
            'mode' => $current['mode']->value,
            'userIds' => $current['userIds'],
            'people' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'role' => $user->id === $owner->id ? 'Owner' : null])->values()->all(),
            'secretFiles' => $secrets['files'],
            'memoryFolder' => $secrets['memoryFolder'],
            'memoryFiles' => $secrets['memoryFiles'],
        ]);
    }

    public function update(UpdateThreadShareRequest $request, Thread $thread): JsonResponse
    {
        $owner = $this->owner($request, $thread);

        $this->sharing->apply($thread, $owner, $request->mode(), $request->userIds());

        $current = $this->sharing->current($thread);

        return response()->json(['mode' => $current['mode']->value, 'userIds' => $current['userIds']]);
    }

    /**
     * The route binding already hides a thread the viewer cannot see (404);
     * a visible thread the viewer does not own answers 403.
     */
    private function owner(Request $request, Thread $thread): User
    {
        $user = $request->user() ?? abort(401);

        abort_unless($this->access->canSee($user, $thread), 404);
        abort_unless($this->access->isOwner($user, $thread), 403);

        return $user;
    }
}
