<?php

namespace App\Http\Controllers\Threads;

use App\Enums\ThreadOwnerSide;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\SendThreadMessageRequest;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Services\Threads\ClearCommand;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class ThreadMessageController extends Controller
{
    public function store(SendThreadMessageRequest $request, Thread $thread, ThreadTurns $turns, ThreadAccess $access): RedirectResponse|JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $user = $request->user();

        if (! $access->canDrive($user, $thread)) {
            return $this->refuse($request, 'You cannot send messages to this thread.');
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return $this->refuse($request, 'Your computer is working on this thread. Hand it back to Yak before sending a message.');
        }

        if (! $thread->isOpen()) {
            return $this->refuse($request, 'This thread is closed.');
        }

        $text = (string) $request->validated('message');
        $model = $request->validated('model');
        $task = $thread->tasks()->latest('id')->firstOrFail();
        $sendKey = $request->validated('send_key');
        $claim = $sendKey === null ? null : "thread-send:{$thread->id}:{$user?->id}:{$sendKey}";

        // A browser that lost the answer to a send repeats it with the same key; the message is already in.
        if ($claim !== null && ! Cache::add($claim, true, now()->addMinutes(10))) {
            return $this->sent($request);
        }

        try {
            if (ClearCommand::matches($text)) {
                if (ThreadTurns::activeTurn($thread) !== null) {
                    $this->release($claim);

                    return $this->refuse($request, 'Stop the running turn before clearing.');
                }

                $continued = route('threads.show', ClearCommand::apply($thread, $task));

                return $request->expectsJson()
                    ? response()->json(['status' => 'sent', 'redirect' => $continued])
                    : redirect()->to($continued)->with('success', 'Started a new thread continued from this one.');
            }

            if (ThreadTurns::activeTurn($thread) === null) {
                $turns->start($task, $text, $user, $model);
                TaskAttachment::storeFromRequest($request, ['yak_task_id' => $task->id]);
            } else {
                $turns->steer(
                    $thread,
                    $text,
                    $user,
                    $model,
                    fn (PendingSteeringMessage $message) => TaskAttachment::storeFromRequest($request, ['pending_steering_message_id' => $message->id]),
                    'thread',
                );
            }
        } catch (ThreadBusy) {
            $this->release($claim);

            return $this->refuse($request, 'This thread is already starting a turn. Give it a moment.');
        } catch (\Throwable $failure) {
            $this->release($claim);

            throw $failure;
        }

        return $this->sent($request);
    }

    /**
     * The page's send queue asks for JSON so a send survives navigation; a plain form post gets the redirect.
     */
    private function sent(Request $request): RedirectResponse|JsonResponse
    {
        return $request->expectsJson() ? response()->json(['status' => 'sent']) : back()->with('success', 'Message sent.');
    }

    private function refuse(Request $request, string $reason): RedirectResponse|JsonResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $reason], 422) : back()->with('error', $reason);
    }

    /**
     * A send that did not go through must stay repeatable with the same key.
     */
    private function release(?string $claim): void
    {
        if ($claim !== null) {
            Cache::forget($claim);
        }
    }

    public function stop(Request $request, Thread $thread, ThreadTurns $turns): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $turns->stop($thread);

        return back()->with('success', 'Stopped.');
    }
}
