<?php

namespace App\Services\Threads;

use App\Enums\SteeringMode;
use App\Exceptions\ThreadActionRefused;
use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Devices\ShortLockWait;
use App\Services\Teleport\ThreadOwnership;
use Illuminate\Support\Str;
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

    public const BUSY_SENTENCE = 'Yak is busy with this conversation. Try again in a few seconds.';

    public function __construct(private readonly SteeringDelivery $delivery) {}

    /**
     * `$message` is the row id (web) or its uuid (app).
     *
     * @throws ThreadActionRefused
     * @throws ValidationException
     */
    public function update(YakTask $rootTask, int|string $message, ?SteeringMode $mode, ?string $text): PendingSteeringMessage
    {
        return $this->shortTransaction(function () use ($rootTask, $message, $mode, $text): PendingSteeringMessage {
            $thread = $this->lockThread($rootTask);
            $pending = $this->findLocked($rootTask, $message);

            if ($mode === SteeringMode::Steer && ! $pending->canSteer()) {
                throw ValidationException::withMessages(['mode' => self::REVIEW_SENTENCE]);
            }

            $pending->update(array_filter(['mode' => $mode, 'text' => $text], fn (mixed $value): bool => $value !== null));

            if ($mode === SteeringMode::Steer && $thread !== null && app(ThreadOwnership::class)->yakMayRunTurns($thread)) {
                if ($pending->uuid === null) {
                    $pending->forceFill(['uuid' => (string) Str::uuid()])->save();
                }

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
        $this->shortTransaction(function () use ($rootTask, $message): void {
            $this->lockThread($rootTask);
            $this->findLocked($rootTask, $message)->withdraw();
        });
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function shortTransaction(\Closure $callback): mixed
    {
        return ShortLockWait::transaction(
            $callback,
            fn (): ThreadActionRefused => new ThreadActionRefused('unavailable', self::BUSY_SENTENCE, [], 503),
        );
    }

    private function lockThread(YakTask $rootTask): ?Thread
    {
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
