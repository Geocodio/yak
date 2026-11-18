<?php

namespace App\Http\Controllers\Threads;

use App\Enums\SteeringMode;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Threads\Concerns\RespondsToThreadActions;
use App\Http\Requests\Api\App\UpdateQueuedMessageRequest;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\QueuedMessages;
use Illuminate\Http\RedirectResponse;

/**
 * Messages waiting for a busy thread: switch one between queued and steered,
 * edit its text, or withdraw it. A message of another thread answers 404.
 */
class ThreadQueuedMessageController extends Controller
{
    use RespondsToThreadActions;

    public function update(UpdateQueuedMessageRequest $request, Thread $thread, string $message, QueuedMessages $queued): RedirectResponse
    {
        return $this->respond($thread, function () use ($request, $thread, $message, $queued): ?string {
            $root = $this->rootTask($thread, $message);
            $queued->update($root, $request->user() ?? abort(401), $this->identifier($message), $request->enum('mode', SteeringMode::class), $request->validated('text'));

            return null;
        });
    }

    public function destroy(Thread $thread, string $message, QueuedMessages $queued): RedirectResponse
    {
        return $this->respond($thread, function () use ($thread, $message, $queued): ?string {
            $queued->withdraw($this->rootTask($thread, $message), $this->identifier($message));

            return null;
        });
    }

    /**
     * A thread with no tasks has no queued messages, so the message is as good as gone.
     */
    private function rootTask(Thread $thread, string $message): YakTask
    {
        $this->refuseClosed($thread);

        if (! $thread->tasks()->exists()) {
            throw ThreadActionRefused::because(ThreadActionRefused::MESSAGE_DELIVERED, QueuedMessages::DELIVERED_SENTENCE);
        }

        return $thread->messagesRootTask();
    }

    private function identifier(string $message): int|string
    {
        return ctype_digit($message) ? (int) $message : $message;
    }
}
