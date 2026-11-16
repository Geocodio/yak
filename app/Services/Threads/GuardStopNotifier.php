<?php

namespace App\Services\Threads;

use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\TaskRun;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use Throwable;

/**
 * Tells whoever can continue that the runaway guard stopped a turn: the
 * driving person by push, or the task's own channel when nobody drives.
 */
class GuardStopNotifier
{
    public function __construct(
        private readonly RunawayGuard $guard,
        private readonly ThreadPush $push,
        private readonly PushRecipients $recipients,
    ) {}

    public function ask(TaskRun $run, string $reason, int $assistantTurns): void
    {
        try {
            $thread = $run->thread;
            $message = $thread !== null && $thread->isPrivate()
                ? 'A turn was stopped and needs you. Open Yak to continue.'
                : $this->guard->message($reason, $assistantTurns) . ($thread !== null ? ' ' . route('threads.show', $thread) : '');

            if ($thread !== null && $run->driver_user_id !== null) {
                $this->push->send(PushEvent::NeedsYou, $thread, $this->recipients->forTurn($run), $message, "guard-run-{$run->id}");

                return;
            }

            if ($run->task !== null) {
                SendNotificationJob::dispatch($run->task, NotificationType::Error, $message, personalize: false);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
