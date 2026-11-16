<?php

namespace Tests\Support;

use App\Services\Threads\ThreadEventWindow;
use Carbon\CarbonImmutable;

/**
 * The event window without Redis: events live in an array, ordered by sequence.
 */
class InMemoryThreadEventWindow extends ThreadEventWindow
{
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $events = [];

    public function push(int $threadId, int $sequence, string $type, array $data, CarbonImmutable $at): void
    {
        $this->events[$threadId][$sequence] = [
            'sequence' => $sequence,
            'type' => $type,
            'data' => $data,
            'createdAt' => $at->toIso8601String(),
        ];
        ksort($this->events[$threadId]);
    }

    public function since(int $threadId, int $after, int $limit): ?array
    {
        $events = $this->events[$threadId] ?? [];

        if ($events === [] || array_key_first($events) > $after + 1) {
            return null;
        }

        return array_slice(array_values(array_filter($events, fn (array $event): bool => $event['sequence'] > $after)), 0, $limit);
    }

    public function forget(int $threadId): void
    {
        unset($this->events[$threadId]);
    }
}
