<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\DataTransferObjects\ThreadMessageInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\SendAppThreadMessageRequest;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Services\Threads\ThreadMessageSender;
use Illuminate\Http\JsonResponse;

class ThreadMessageController extends Controller
{
    public function store(SendAppThreadMessageRequest $request, Thread $thread, ThreadMessageSender $sender): JsonResponse
    {
        $taskId = $thread->headTask()?->id;
        $input = new ThreadMessageInput(
            (string) $request->validated('message'),
            $request->validated('model'),
            $request->validated('intent'),
            $request->validated('send_key'),
            $request->delivery(),
            fn (ThreadTurnMessage|PendingSteeringMessage $message) => $message instanceof ThreadTurnMessage
                ? TaskAttachment::storeFromRequest($request, ['yak_task_id' => $taskId, 'thread_turn_message_id' => $message->id])
                : TaskAttachment::storeFromRequest($request, ['pending_steering_message_id' => $message->id]),
            $request->acknowledgedPrivatePublish(),
        );

        return response()->json($sender->send($thread, $request->user() ?? abort(401), $input)->toArray());
    }
}
