<?php

namespace App\Services\Threads;

use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Jobs\AfterTurn\RetryQueuedMessageStartJob;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Teleport\ThreadNotOwnedByYak;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Starts one turn for the messages a person queued while a turn ran. A
 * completed turn is the only trigger: after a failed, stopped or interrupted
 * turn the messages stay queued for the next message the person sends.
 */
class QueuedMessageRunner
{
    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 5;

    private const MAX_ATTEMPTS = 6;

    private const RETRY_SECONDS = 10;

    public function __construct(private readonly ThreadTurns $turns) {}

    /**
     * The new turn, or null when nothing was started. Safe to call more than
     * once for the same turn: the first call drains the messages, so a repeat
     * finds nothing to send. A start that meets a lock that stays held is
     * retried by a delayed job, so the messages are not left waiting for the
     * person to send again.
     */
    public function afterTurn(TaskRun $run, int $attempt = 1): ?TaskRun
    {
        if ($run->turn_state !== TurnState::Completed || $run->thread_id === null) {
            return null;
        }

        try {
            return Cache::lock("thread-queued-start:{$run->thread_id}", self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn (): ?TaskRun => $this->startQueuedTurn($run->thread_id));
        } catch (LockTimeoutException) {
            return $this->retryLater($run, $attempt);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 503) {
                return $this->retryLater($run, $attempt);
            }

            report($exception);

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function retryLater(TaskRun $run, int $attempt): null
    {
        if ($attempt < self::MAX_ATTEMPTS) {
            RetryQueuedMessageStartJob::dispatch($run->id, $attempt + 1)->delay(now()->addSeconds(self::RETRY_SECONDS * $attempt));
        } else {
            report(new \RuntimeException("Queued messages of thread {$run->thread_id} still could not start after {$attempt} attempts."));
        }

        return null;
    }

    private function startQueuedTurn(int $threadId): ?TaskRun
    {
        $thread = Thread::query()->find($threadId);

        if ($thread === null
            || $thread->status !== ThreadStatus::Open
            || $thread->blocked_reason !== null
            || ThreadTurns::activeTurn($thread) !== null) {
            return null;
        }

        $head = $thread->headTask();

        if ($head === null || $head->status === TaskStatus::AwaitingClarification) {
            return null;
        }

        $oldest = PendingSteeringMessage::query()
            ->where('root_task_id', $thread->messagesRootTask()->id)
            ->where('source', 'thread')
            ->whereNotExists(fn ($delivered) => $delivered->from('thread_turn_messages')->whereColumn('thread_turn_messages.pending_steering_message_id', 'pending_steering_messages.id'))
            ->orderBy('id')
            ->first();

        if ($oldest === null) {
            return null;
        }

        try {
            return $this->turns->start($head, '', null, $oldest->model, TaskRunKind::FollowUp);
        } catch (ThreadBusy|ThreadNotOwnedByYak) {
            return null;
        }
    }
}
