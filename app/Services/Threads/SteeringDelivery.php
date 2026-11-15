<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Enums\SteeringMode;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Services\Devices\ShortLockWait;

/**
 * Hands a steering message to the thread's running turn. The message keeps its
 * uuid, which the driver follows through the runner's lifecycle lines.
 */
class SteeringDelivery
{
    public function __construct(private readonly ThreadCommands $commands) {}

    /**
     * False when no turn is running on a ready runner or the message is already
     * sent; true when a new prompt drained it; the message then stays queued.
     */
    public function deliver(Thread $thread, PendingSteeringMessage $message): bool
    {
        return ShortLockWait::threadTransaction(function () use ($thread, $message): bool {
            $locked = Thread::query()->lockForUpdate()->findOrFail($thread->id);
            $run = ThreadTurns::runningTurn($locked);

            if ($run === null || ! in_array($locked->runner_state, [RunnerState::Idle, RunnerState::Busy], true)) {
                return false;
            }

            // A new prompt may have drained this message between the insert and the lock; it was already sent.
            if (! PendingSteeringMessage::query()->whereKey($message->id)->lockForUpdate()->exists()) {
                return true;
            }

            $uuid = (string) $message->uuid;

            if (ThreadTurnMessage::query()->where('uuid', $uuid)->exists()) {
                return true;
            }

            if ($message->model !== null && $message->model !== $locked->runner_model) {
                $this->commands->setModel($locked, $message->model);
                $locked->forceFill(['model' => $message->model])->save();
            }

            $this->commands->steer($locked, $uuid, SteeringText::for($locked, $message));

            ThreadTurnMessage::query()->create([
                'task_run_id' => $run->id,
                'uuid' => $uuid,
                'kind' => TurnMessageKind::Steer,
                'state' => TurnMessageState::Sent,
                'pending_steering_message_id' => $message->id,
                'send_key' => $message->send_key,
            ]);
            ThreadTurns::trackUuid($run, $uuid);

            $message->forceFill(['mode' => SteeringMode::Steer])->save();

            return true;
        });
    }
}
