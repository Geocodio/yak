<?php

namespace App\Services\Stacks;

use App\Models\Thread;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts the current state of a sub-thread into its parent's conversation as a
 * stored `child` event; the page keeps the latest event per child as one card.
 * The event rides the parent's channel, so a private stack stays private.
 */
class ChildCardPublisher
{
    public function __construct(private readonly ThreadEventPublisher $events) {}

    public function publish(Thread $child): void
    {
        $parentId = $child->parent_thread_id;

        if ($parentId === null) {
            return;
        }

        try {
            $child->refresh();

            $this->events->append($parentId, 'child', [
                'childThreadId' => $child->id,
                'name' => $child->name,
                'kind' => $child->kind->value,
                'model' => $child->model,
                'state' => StackListing::state($child),
                'step' => $child->current_step,
                'resultSummary' => $child->result_summary,
                'branch' => $child->branch,
                'messagesPaused' => $child->messages_paused_at !== null,
            ]);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Child card could not be published', ['thread_id' => $child->id, 'error' => $exception->getMessage()]);
        }
    }
}
