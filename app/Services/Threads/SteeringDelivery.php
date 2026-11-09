<?php

namespace App\Services\Threads;

use App\Enums\SteeringMode;
use App\Jobs\FlushSteeringMessagesJob;
use App\Models\PendingSteeringMessage;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\TaskAttachmentStager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands steering messages to a thread's running turn. Each message keeps a
 * uuid on its row that the driver follows through the runner's lifecycle
 * lines; a message is withdrawable only until it is sent. A message that
 * finds no running turn goes through the follow-up routing instead.
 */
class SteeringDelivery
{
    public function __construct(private readonly ThreadCommands $commands, private readonly TaskAttachmentStager $stager) {}

    /**
     * Sends from a request or job path. A failure leaves the message waiting
     * for the driver's next sweep, so it never fails the caller.
     */
    public static function deliverQuietly(YakTask $task): void
    {
        try {
            app(self::class)->deliver($task);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Returns how many messages were sent to the running turn.
     */
    public function deliver(YakTask $task): int
    {
        $root = $task->rootTask();
        $thread = Thread::query()->find($task->thread_id ?? $root->thread_id);

        if ($thread === null) {
            return 0;
        }

        $messages = PendingSteeringMessage::steeredFor($task)->filter->canSteer();

        if ($messages->isEmpty()) {
            return 0;
        }

        if ($thread->runningTurn() === null) {
            FlushSteeringMessagesJob::dispatch($root->id);

            return 0;
        }

        // Staged before any lock is taken, so a slow copy does not hold up
        // someone withdrawing a message.
        $sections = $messages->mapWithKeys(fn (PendingSteeringMessage $message): array => [
            $message->id => $message->attachments->isEmpty() || (string) $thread->sandbox_container === ''
                ? ''
                : $this->stager->stage((string) $thread->sandbox_container, $message->attachments->all()),
        ]);

        $sent = 0;

        foreach ($messages as $message) {
            if (! $this->send($thread, $message->id, $sections[$message->id] ?? '')) {
                break;
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * One message per transaction with the thread row locked, so the driver
     * cannot rewrite the turn's command states underneath it and a message
     * is never sent twice. False when the turn is gone or the message was
     * withdrawn or sent in the meantime.
     */
    private function send(Thread $thread, int $messageId, string $attachmentSection): bool
    {
        return DB::transaction(function () use ($thread, $messageId, $attachmentSection): bool {
            $lockedThread = Thread::query()->lockForUpdate()->findOrFail($thread->id);
            $run = $lockedThread->runningTurn();

            if ($run === null) {
                return false;
            }

            $message = PendingSteeringMessage::query()
                ->whereKey($messageId)
                ->where('mode', SteeringMode::Steer)
                ->whereNull('sent_at')
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return true;
            }

            // A message resent after a lost runner keeps its uuid.
            $uuid = $message->uuid ?? (string) Str::uuid();
            $text = PendingSteeringMessage::STEERING_PREAMBLE . $message->text
                . ($attachmentSection === '' ? '' : "\n\n" . $attachmentSection);

            if ($message->model !== null && $message->model !== $lockedThread->runner_model) {
                $this->commands->setModel($lockedThread, $message->model);
                $lockedThread->forceFill(['model' => $message->model])->save();
            }

            $this->commands->steer($lockedThread, $uuid, $text, $message->priority);

            $message->forceFill(['uuid' => $uuid, 'task_run_id' => $run->id, 'sent_at' => now()])->save();

            $tracker = TurnTracker::fromRun($run);
            $tracker->track($uuid);
            $run->forceFill($tracker->attributes())->save();

            return true;
        });
    }
}
