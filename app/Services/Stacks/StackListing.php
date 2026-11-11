<?php

namespace App\Services\Stacks;

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Services\Threads\ThreadTurns;

class StackListing
{
    /**
     * @return list<array{name: string, kind: string, state: string, latestResult: ?string}>
     */
    public static function for(Thread $caller): array
    {
        $rootId = $caller->root_thread_id ?? $caller->id;

        $rows = [];

        foreach (Thread::query()
            ->where(fn ($query) => $query->whereKey($rootId)->orWhere('root_thread_id', $rootId))
            ->orderBy('id')
            ->get() as $thread) {
            $rows[] = [
                'name' => $thread->name,
                'kind' => $thread->kind->value,
                'state' => self::state($thread),
                'latestResult' => $thread->result_summary ?? $thread->current_step,
            ];
        }

        return $rows;
    }

    private static function state(Thread $thread): string
    {
        if (! $thread->isOpen()) {
            return 'closed';
        }

        if (StackThreadMessage::query()->where('from_thread_id', $thread->id)->where('kind', StackThreadMessageKind::Failure)->exists()) {
            return 'failed';
        }

        if ($thread->result_summary !== null) {
            return 'done';
        }

        if ($thread->queued_for_slot_at !== null || ! $thread->runs()->exists()) {
            return 'queued';
        }

        return ThreadTurns::activeTurn($thread) !== null ? 'working' : 'waiting';
    }
}
