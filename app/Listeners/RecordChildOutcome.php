<?php

namespace App\Listeners;

use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\Thread;
use App\Services\Stacks\ChildCardPublisher;
use App\Services\Stacks\ChildOutcomeRecorder;

/**
 * Reports the end of a sub-thread's turn to its parent.
 */
class RecordChildOutcome
{
    public function __construct(private readonly ChildOutcomeRecorder $recorder, private readonly ChildCardPublisher $cards) {}

    public function handleCompleted(TurnCompleted $event): void
    {
        $child = $this->childOf($event->run->thread_id);

        if ($child !== null) {
            $this->recorder->recordFinished($child, $event->run);
            $this->cards->publish($child);
        }
    }

    public function handleFailed(TurnFailed $event): void
    {
        $child = $this->childOf($event->run->thread_id);

        if ($child !== null) {
            $this->recorder->recordFailed($child, $event->run);
            $this->cards->publish($child);
        }
    }

    private function childOf(?int $threadId): ?Thread
    {
        $thread = $threadId === null ? null : Thread::query()->withoutGlobalScopes()->find($threadId);

        return $thread?->isChild() ? $thread : null;
    }
}
