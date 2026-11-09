<?php

namespace App\Services\Threads;

use App\Events\ThreadActivity;
use App\Models\Thread;
use App\Models\ThreadEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stores every realtime event of a thread with a per-thread sequence, then broadcasts it.
 * Stored events are the source of truth: a failed broadcast is reported and browsers catch up
 * over the events endpoint.
 *
 * Event types and their payloads:
 * - entry: one finished transcript entry (assistant blocks, tool result, system, result)
 * - delta: batched streaming text of one block {blockKey, kind, text}
 * - stream.reset: discard the open streaming blocks
 * - turn: a turn changed state {turnId, state}
 * - thread: status, sandbox state or block changed {status, sandboxState, blockedReason, blockedUntil}
 * - message.state: a queued or sent message changed state
 * - diff.updated: the thread's diff changed
 */
class ThreadEventPublisher
{
    /** @var list<ThreadEvent> */
    private array $queue = [];

    /**
     * Record an event while holding the thread row lock inside a transaction, then call flush() after commit.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(Thread $lockedThread, string $type, array $payload): ThreadEvent
    {
        $sequence = (int) $lockedThread->event_sequence + 1;

        $event = ThreadEvent::query()->create([
            'thread_id' => $lockedThread->id,
            'sequence' => $sequence,
            'type' => $type,
            'payload' => $payload,
        ]);

        Thread::query()->whereKey($lockedThread->id)->update(['event_sequence' => $sequence]);
        $lockedThread->event_sequence = $sequence;
        $lockedThread->syncOriginalAttribute('event_sequence');

        $this->queue[] = $event;

        // A rolled back transaction leaves no row, so its event must not be broadcast either.
        DB::afterRollBack(fn () => $this->queue = array_values(array_filter(
            $this->queue,
            fn (ThreadEvent $queued): bool => $queued !== $event,
        )));

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(int $threadId, string $type, array $payload): ThreadEvent
    {
        $event = DB::transaction(fn (): ThreadEvent => $this->record(
            Thread::query()->lockForUpdate()->findOrFail($threadId),
            $type,
            $payload,
        ));

        $this->flush();

        return $event;
    }

    /**
     * The thread's status, sandbox and block as the page shows them, recorded under the caller's row lock.
     */
    public function recordThreadState(Thread $lockedThread): ThreadEvent
    {
        return $this->record($lockedThread, 'thread', $this->threadState($lockedThread));
    }

    public function appendThreadState(int $threadId): ThreadEvent
    {
        $event = DB::transaction(function () use ($threadId): ThreadEvent {
            $thread = Thread::query()->lockForUpdate()->findOrFail($threadId);

            return $this->recordThreadState($thread);
        });

        $this->flush();

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function threadState(Thread $thread): array
    {
        return [
            'status' => $thread->status->value,
            'sandboxState' => $thread->sandbox_state->value,
            'blockedReason' => $thread->blocked_reason?->value,
            'blockedUntil' => $thread->blocked_until?->toIso8601String(),
        ];
    }

    public function flush(): void
    {
        $events = $this->queue;
        $this->queue = [];

        foreach ($events as $event) {
            try {
                broadcast(new ThreadActivity($event));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
