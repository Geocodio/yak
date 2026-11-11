<?php

namespace App\Http\Controllers\Threads;

use App\Enums\ThreadOwnerSide;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\SendThreadMessageRequest;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ThreadMessageController extends Controller
{
    public function store(SendThreadMessageRequest $request, Thread $thread, ThreadTurns $turns, ThreadAccess $access): RedirectResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $user = $request->user();

        if (! $access->canDrive($user, $thread)) {
            return back()->with('error', 'You cannot send messages to this thread.');
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return back()->with('error', 'Your computer is working on this thread. Hand it back to Yak before sending a message.');
        }

        if (! $thread->isOpen()) {
            return back()->with('error', 'This thread is closed.');
        }

        $text = (string) $request->validated('message');
        $model = $request->validated('model');
        $task = $thread->tasks()->latest('id')->firstOrFail();

        try {
            if (ThreadTurns::activeTurn($thread) === null) {
                $turns->start($task, $text, $user, $model);
                TaskAttachment::storeFromRequest($request, ['yak_task_id' => $task->id]);
            } else {
                $message = $turns->steer($thread, $text, $user, $model);
                TaskAttachment::storeFromRequest($request, ['pending_steering_message_id' => $message->id]);
            }
        } catch (ThreadBusy) {
            return back()->with('error', 'This thread is already starting a turn. Give it a moment.');
        }

        return back()->with('success', 'Message sent.');
    }

    public function stop(Request $request, Thread $thread, ThreadTurns $turns): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $turns->stop($thread);

        return back()->with('success', 'Stopped.');
    }
}
