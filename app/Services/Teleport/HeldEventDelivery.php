<?php

namespace App\Services\Teleport;

use App\Models\HeldThreadEvent;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns the events held while a device owned a thread into the first message after teleport up.
 */
class HeldEventDelivery
{
    /**
     * The text of the first message, or null when nothing was held.
     */
    public function firstMessageFor(Thread $thread): ?string
    {
        $events = $this->undelivered($thread);

        if ($events->isEmpty()) {
            return null;
        }

        $lines = $events->map(fn (HeldThreadEvent $event): string => trim("- {$event->kind->value}: {$event->title}. {$event->summary} {$event->pull_request_url}"));

        return "While you were away on your computer, these events arrived:\n" . $lines->implode("\n");
    }

    public function markDelivered(Thread $thread): int
    {
        return HeldThreadEvent::query()->where('thread_id', $thread->id)->whereNull('delivered_at')->update(['delivered_at' => now()]);
    }

    /**
     * @return Collection<int, HeldThreadEvent>
     */
    private function undelivered(Thread $thread): Collection
    {
        return HeldThreadEvent::query()->where('thread_id', $thread->id)->whereNull('delivered_at')->orderBy('id')->get();
    }
}
