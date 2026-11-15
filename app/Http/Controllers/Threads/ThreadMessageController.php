<?php

namespace App\Http\Controllers\Threads;

use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\SendThreadMessageRequest;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadMessageSender;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ThreadMessageController extends Controller
{
    public function store(SendThreadMessageRequest $request, Thread $thread, ThreadMessageSender $sender, ThreadAccess $access): RedirectResponse|JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $user = $request->user();

        if (! $access->canDrive($user, $thread)) {
            return $this->refuse($request, 'You cannot send messages to this thread.');
        }

        $taskId = $thread->headTask()?->id;
        $input = new ThreadMessageInput(
            (string) $request->validated('message'),
            $request->validated('model'),
            $request->validated('intent'),
            $request->validated('send_key'),
            MessageDelivery::Steer,
            fn (ThreadTurnMessage|PendingSteeringMessage $message) => $message instanceof ThreadTurnMessage
                ? TaskAttachment::storeFromRequest($request, ['yak_task_id' => $taskId, 'thread_turn_message_id' => $message->id])
                : TaskAttachment::storeFromRequest($request, ['pending_steering_message_id' => $message->id]),
            $request->acknowledgedPrivatePublish(),
        );

        try {
            $result = $sender->send($thread, $user ?? abort(401), $input);
        } catch (ThreadActionRefused $refusal) {
            return $this->refuse($request, $refusal->getMessage());
        }

        if ($result->threadId !== null) {
            $continued = route('threads.show', $result->threadId);

            return $request->expectsJson()
                ? response()->json(['status' => 'sent', 'redirect' => $continued])
                : redirect()->to($continued)->with('success', 'Started a new thread continued from this one.');
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

    public function stop(Request $request, Thread $thread, ThreadTurns $turns): RedirectResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        $turns->stop($thread);

        return back()->with('success', 'Stopped.');
    }
}
