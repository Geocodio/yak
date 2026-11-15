<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Enums\SteeringMode;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdateQueuedMessageRequest;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\QueuedMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class QueuedMessageController extends Controller
{
    public function update(UpdateQueuedMessageRequest $request, Thread $thread, string $message, QueuedMessages $queued): JsonResponse
    {
        $this->assertOpen($thread);
        $queued->update($this->rootTask($thread), $request->user() ?? abort(401), $message, $request->enum('mode', SteeringMode::class), $request->validated('text'));

        return response()->json(['status' => 'updated']);
    }

    public function destroy(Thread $thread, string $message, QueuedMessages $queued): Response
    {
        $this->assertOpen($thread);
        $queued->withdraw($this->rootTask($thread), $message);

        return response()->noContent();
    }

    /**
     * A thread with no tasks has no queued messages, so the message is as good as gone.
     */
    private function rootTask(Thread $thread): YakTask
    {
        if (! $thread->tasks()->exists()) {
            throw ThreadActionRefused::because(ThreadActionRefused::MESSAGE_DELIVERED, QueuedMessages::DELIVERED_SENTENCE);
        }

        return $thread->messagesRootTask();
    }

    private function assertOpen(Thread $thread): void
    {
        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }
    }
}
