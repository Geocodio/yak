<?php

namespace App\Services\Threads;

use App\Enums\SteeringMode;
use App\Exceptions\ThreadActionRefused;
use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Teleport\ThreadOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes to messages waiting on a conversation: switch between queued and steered,
 * edit the text, or withdraw. A message that already reached the running turn is history
 * and refuses every change.
 */
class QueuedMessages
{
    public const DELIVERED_SENTENCE = 'Yak already has that message.';

    public const REVIEW_SENTENCE = 'A GitHub review waits for the follow-up, so the reviewer is asked to look again.';

    public function __construct(private readonly SteeringDelivery $delivery) {}

    /**
     * `$message` is the row id (web) or its uuid (app).
     *
     * @throws ThreadActionRefused
     * @throws ValidationException
     */
    public function update(YakTask $rootTask, int|string $message, ?SteeringMode $mode, ?string $text): PendingSteeringMessage
    {
        return DB::transaction(function () use ($rootTask, $message, $mode, $text): PendingSteeringMessage {
            $thread = $this->lockThread($rootTask);
            $pending = $this->findLocked($rootTask, $message);

            if ($mode === SteeringMode::Steer && ! $pending->canSteer()) {
                throw ValidationException::withMessages(['mode' => self::REVIEW_SENTENCE]);
            }

            $pending->update(array_filter(['mode' => $mode, 'text' => $text], fn (mixed $value): bool => $value !== null));

            if ($mode === SteeringMode::Steer && $thread !== null && app(ThreadOwnership::class)->yakMayRunTurns($thread)) {
                $this->delivery->deliver($thread, $pending);
            }

            return $pending;
        });
    }

    /**
     * @throws ThreadActionRefused
     */
    public function withdraw(YakTask $rootTask, int|string $message): void
    {
        DB::transaction(function () use ($rootTask, $message): void {
            $this->lockThread($rootTask);
            $this->findLocked($rootTask, $message)->withdraw();
        });
    }

    private function lockThread(YakTask $rootTask): ?Thread
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // A lock wait stays under 5 seconds; the timeout surfaces as a QueryException, which the client APIs answer with 503 unavailable.
            DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
        }

        return $rootTask->thread_id === null ? null : Thread::query()->lockForUpdate()->find($rootTask->thread_id);
    }

    /**
     * The message, locked against the runner handing it to Yak.
     *
     * @throws ThreadActionRefused when it is gone or was already handed to a turn
     */
    private function findLocked(YakTask $rootTask, int|string $message): PendingSteeringMessage
    {
        $pending = PendingSteeringMessage::where('root_task_id', $rootTask->id)
            ->where(is_int($message) ? 'id' : 'uuid', $message)
            ->lockForUpdate()
            ->with('attachments')
            ->first();

        $isDelivered = $pending !== null && ThreadTurnMessage::query()
            ->where(fn ($query) => $query->where('pending_steering_message_id', $pending->id)->orWhere('uuid', $pending->uuid))
            ->exists();

        if ($pending === null || $isDelivered) {
            throw ThreadActionRefused::because(ThreadActionRefused::MESSAGE_DELIVERED, self::DELIVERED_SENTENCE);
        }

        return $pending;
    }
}
