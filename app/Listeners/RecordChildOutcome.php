<?php

namespace App\Listeners;

use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\Thread;
use App\Services\Stacks\ChildOutcomeRecorder;

/**
 * Reports the end of a sub-thread's turn to its parent.
 */
class RecordChildOutcome
{
    public function __construct(private readonly ChildOutcomeRecorder $recorder) {}

    public function handleCompleted(TurnCompleted $event): void
    {
        $child = $this->childOf($event->run->thread_id);

        if ($child !== null) {
            $this->recorder->recordFinished($child, $event->run);
        }
    }

    public function handleFailed(TurnFailed $event): void
    {
        $child = $this->childOf($event->run->thread_id);

        if ($child !== null) {
            $this->recorder->recordFailed($child, $event->run);
        }
    }

    private function childOf(?int $threadId): ?Thread
    {
        $thread = $threadId === null ? null : Thread::query()->withoutGlobalScopes()->find($threadId);

        return $thread?->isChild() ? $thread : null;
    }
}
