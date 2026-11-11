<?php

namespace App\Services\Threads;

use App\Events\ThreadEventBroadcast;
use App\Models\Thread;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Allocates a per-thread sequence for every realtime event, stores finished ones in
 * `thread_events` (the source of truth), mirrors all of them into the Redis window and
 * broadcasts them. A failed broadcast is reported; browsers catch up over HTTP.
 *
 * Event types: entry, delta, stream.reset, turn, thread, message.state, diff.updated.
 */
class ThreadEventPublisher
{
    /** @var array<int, array{sequence: int, threadId: int, type: string, data: array<string, mixed>, createdAt: CarbonImmutable}> */
    private array $queue = [];

    private int $nextQueueId = 0;

    public function __construct(private readonly ThreadEventWindow $window) {}

    /**
     * Records an event inside the caller's transaction (which holds the thread row lock), then
     * call flush() after commit. A rolled back transaction drops the event.
     *
     * @param  array<string, mixed>  $data
     * @return array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}
     */
    public function record(int $lockedThreadId, string $type, array $data, bool $isStored = true): array
    {
        $current = (int) DB::table('threads')->where('id', $lockedThreadId)->lockForUpdate()->value('event_sequence');
        $sequence = $current + 1;
        $createdAt = CarbonImmutable::now();

        DB::table('threads')->where('id', $lockedThreadId)->update(['event_sequence' => $sequence]);

        if ($isStored) {
            DB::table('thread_events')->insert([
                'thread_id' => $lockedThreadId,
                'sequence' => $sequence,
                'type' => $type,
                'payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'created_at' => $createdAt,
            ]);
        }

        $queueId = $this->nextQueueId++;
        $this->queue[$queueId] = [
            'sequence' => $sequence,
            'threadId' => $lockedThreadId,
            'type' => $type,
            'data' => $data,
            'createdAt' => $createdAt,
        ];

        DB::afterRollBack(function () use ($queueId): void {
            unset($this->queue[$queueId]);
        });

        return ['sequence' => $sequence, 'type' => $type, 'data' => $data, 'createdAt' => $createdAt->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}
     */
    public function append(int $threadId, string $type, array $data, bool $isStored = true): array
    {
        $event = DB::transaction(fn (): array => $this->record($threadId, $type, $data, $isStored));

        $this->flush();

        return $event;
    }

    /**
     * @return array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}
     */
    public function recordThreadState(int $lockedThreadId): array
    {
        return $this->record($lockedThreadId, 'thread', $this->threadState($lockedThreadId));
    }

    /**
     * @return array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}
     */
    public function appendThreadState(int $threadId): array
    {
        $event = DB::transaction(fn (): array => $this->recordThreadState($threadId));

        $this->flush();

        return $event;
    }

    public function flush(): void
    {
        $events = $this->queue;
        $this->queue = [];

        foreach ($events as $event) {
            try {
                $this->window->push($event['threadId'], $event['sequence'], $event['type'], $event['data'], $event['createdAt']);
            } catch (Throwable $exception) {
                report($exception);
            }

            try {
                broadcast(new ThreadEventBroadcast(
                    $event['threadId'],
                    $event['sequence'],
                    $event['type'],
                    $event['data'],
                    $event['createdAt']->toIso8601String(),
                ));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @return array{status: string, sandboxState: string, runnerState: string, blockedReason: string|null}
     */
    private function threadState(int $threadId): array
    {
        $thread = Thread::withoutGlobalScopes()->findOrFail($threadId);

        return [
            'status' => $thread->status->value,
            'sandboxState' => $thread->sandbox_state->value,
            'runnerState' => $thread->runner_state->value,
            'blockedReason' => $thread->blocked_reason?->value,
        ];
    }
}
