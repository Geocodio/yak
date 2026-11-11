<?php

namespace App\Http\Controllers\Threads;

use App\Enums\ThreadOwnerSide;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\TeleportRequestRequest;
use App\Models\Device;
use App\Models\Thread;
use App\Services\Teleport\Teleports;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The web side of a teleport down only marks the thread as waiting for the person's computer.
 * It never reaches a computer and never stops a turn.
 */
class ThreadTeleportRequestController extends Controller
{
    public function store(TeleportRequestRequest $request, Thread $thread): RedirectResponse
    {
        $this->authorizeDrive($thread);

        abort_unless($thread->owner_side === ThreadOwnerSide::Yak && $thread->openTeleport() === null, 409, 'This thread is already moving or on a computer.');

        // Teleporting down pushes the branch to GitHub through WipPreserver.
        ThreadPublishGuard::assertAcknowledged($thread, $request->acknowledgedPrivatePublish());

        $thread->forceFill([
            'waiting_for_computer_at' => now(),
            'teleport_requested_running_turn' => $request->validated('running_turn'),
        ])->save();

        return back();
    }

    public function destroy(Thread $thread, Teleports $teleports): RedirectResponse
    {
        $this->authorizeDrive($thread);

        $open = $thread->openTeleport();

        if ($open !== null) {
            $teleports->cancel(Device::query()->findOrFail($open->device_id), $open, 'user_cancelled', null);
        }

        $thread->forceFill(['waiting_for_computer_at' => null, 'teleport_requested_running_turn' => null])->save();

        return back();
    }

    private function authorizeDrive(Thread $thread): void
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);
    }
}
