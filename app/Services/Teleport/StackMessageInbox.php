<?php

namespace App\Services\Teleport;

use App\Enums\StackMessageKind;
use App\Models\StackMessage;
use App\Models\Thread;
use Illuminate\Support\Collection;

/**
 * Results that waited on Yak for a thread a device now owns, read by the plugin hook until acknowledged.
 */
class StackMessageInbox
{
    public const int MAX_TEXT = 20000;

    public function record(Thread $parent, StackMessageKind $kind, ?Thread $from, string $text): StackMessage
    {
        return StackMessage::query()->create([
            'thread_id' => $parent->id,
            'kind' => $kind,
            'from_thread_id' => $from?->id,
            'text' => mb_substr($text, 0, self::MAX_TEXT),
        ]);
    }

    /**
     * Oldest first; one more than the limit is returned so the caller can tell whether more remain.
     *
     * @return Collection<int, StackMessage>
     */
    public function pending(Thread $thread, int $limit): Collection
    {
        return StackMessage::query()
            ->where('thread_id', $thread->id)
            ->whereNull('delivered_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function ack(Thread $thread, int $throughId): int
    {
        return StackMessage::query()
            ->where('thread_id', $thread->id)
            ->whereNull('delivered_at')
            ->where('id', '<=', $throughId)
            ->update(['delivered_at' => now()]);
    }
}
