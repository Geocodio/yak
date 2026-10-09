<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\SteeringMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\UpdateQueuedMessageRequest;
use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Messages waiting for a busy task: switch one between queued and steered,
 * or withdraw it (to drop it, or to edit it back in the composer). Each
 * may already have reached Yak by the time the request lands.
 */
class QueuedMessageController extends Controller
{
    public function update(UpdateQueuedMessageRequest $request, YakTask $task, int $message): RedirectResponse
    {
        $mode = $request->enum('mode', SteeringMode::class);

        $error = DB::transaction(function () use ($task, $message, $mode): ?string {
            $pending = $this->findLocked($task, $message);

            if ($pending === null) {
                return 'Yak already has that message.';
            }

            if ($mode === SteeringMode::Steer && ! $pending->canSteer()) {
                return 'A GitHub review waits for the follow-up, so the reviewer is asked to look again.';
            }

            $pending->update(['mode' => $mode]);

            return null;
        });

        return $this->back($task, $error);
    }

    public function destroy(YakTask $task, int $message): RedirectResponse
    {
        $error = DB::transaction(function () use ($task, $message): ?string {
            $pending = $this->findLocked($task, $message);

            if ($pending === null) {
                return 'Yak already has that message.';
            }

            $pending->withdraw();

            return null;
        });

        return $this->back($task, $error);
    }

    /**
     * The message, locked against the runner handing it to Yak until the
     * request is done with it. Null once it has been delivered or flushed
     * into a follow-up.
     */
    private function findLocked(YakTask $task, int $message): ?PendingSteeringMessage
    {
        return PendingSteeringMessage::where('root_task_id', $task->rootTask()->id)
            ->lockForUpdate()
            ->with('attachments')
            ->find($message);
    }

    private function back(YakTask $task, ?string $error): RedirectResponse
    {
        $redirect = redirect()->route('tasks.show', $task);

        return $error === null ? $redirect : $redirect->with('error', $error);
    }
}
