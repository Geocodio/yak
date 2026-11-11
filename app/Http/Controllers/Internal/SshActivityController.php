<?php

namespace App\Http\Controllers\Internal;

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SshActivityController extends Controller
{
    public function __invoke(Request $request, ThreadSandbox $sandbox): JsonResponse
    {
        $address = $request->validate(['address' => ['required', 'string', 'max:45']])['address'];
        $thread = SandboxSshAddress::threadFor($address);

        if ($thread === null || ! $this->isReachable($thread)) {
            return response()->json(['state' => 'gone'], 410);
        }

        $sandbox->markSessionOpen($thread);

        return response()->json(['state' => 'ok']);
    }

    /**
     * The same threads the listeners endpoint binds, minus closed ones: anything else must drop its open sessions.
     */
    private function isReachable(Thread $thread): bool
    {
        return $thread->owner_side === ThreadOwnerSide::Yak
            && $thread->status !== ThreadStatus::Closed
            && in_array($thread->sandbox_state, [
                ThreadSandboxState::Starting,
                ThreadSandboxState::Running,
                ThreadSandboxState::Paused,
                ThreadSandboxState::Stopped,
            ], true);
    }
}
