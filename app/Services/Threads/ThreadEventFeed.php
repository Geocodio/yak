<?php

namespace App\Services\Threads;

use App\Models\Thread;
use App\Models\ThreadEvent;
use Throwable;

/**
 * Catch-up reads of a thread's events for the web and the app: the Redis window when it covers the range,
 * the database otherwise, and the database too when Redis fails.
 */
class ThreadEventFeed
{
    public function __construct(private readonly ThreadEventWindow $window, private readonly ThreadEventProjector $projector) {}

    /**
     * @return array{events: list<array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}>, latestSequence: int, source: 'window'|'database'}
     */
    public function since(Thread $thread, int $after, int $limit): array
    {
        try {
            $events = $this->window->since($thread->id, $after, $limit);
        } catch (Throwable $exception) {
            report($exception);
            $events = null;
        }

        $source = 'window';

        if ($events === null) {
            $source = 'database';
            $events = array_values(ThreadEvent::query()
                ->where('thread_id', $thread->id)
                ->where('sequence', '>', $after)
                ->orderBy('sequence')
                ->limit($limit)
                ->get()
                ->map(fn (ThreadEvent $event): array => $this->shape($event))
                ->all());
        }

        return [
            'events' => $events,
            'latestSequence' => (int) Thread::withoutGlobalScopes()->whereKey($thread->id)->value('event_sequence'),
            'source' => $source,
        ];
    }

    /**
     * @return array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}
     */
    public function one(Thread $thread, int $sequence): array
    {
        return $this->shape(ThreadEvent::query()->where('thread_id', $thread->id)->where('sequence', $sequence)->firstOrFail());
    }

    /**
     * @return array{sequence: int, type: string, data: array<string, mixed>, createdAt: string}
     */
    private function shape(ThreadEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'type' => $event->type,
            'data' => $event->type === 'entry' ? $this->projector->withHtml($event->payload) : $event->payload,
            'createdAt' => $event->created_at->toIso8601String(),
        ];
    }
}
