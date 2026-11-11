<?php

namespace App\Listeners;

use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Stacks\ChildCardPublisher;
use App\Services\Stacks\ChildOutcomeRecorder;
use Closure;
use Throwable;

/**
 * Reports the end of a sub-thread's turn to its parent.
 */
class RecordChildOutcome
{
    public function __construct(private readonly ChildOutcomeRecorder $recorder, private readonly ChildCardPublisher $cards) {}

    public function handleCompleted(TurnCompleted $event): void
    {
        $child = $this->childOf($event->run->thread_id);

        if ($child !== null && $this->claimOutcome($event->run)) {
            $this->record($event->run, fn () => $this->recorder->recordFinished($child, $event->run));
            $this->cards->publish($child);
        }
    }

    public function handleFailed(TurnFailed $event): void
    {
        $child = $this->childOf($event->run->thread_id);

        if ($child !== null && $this->claimOutcome($event->run)) {
            $this->record($event->run, fn () => $this->recorder->recordFailed($child, $event->run));
            $this->cards->publish($child);
        }
    }

    /**
     * Runs the recorder; a throw gives the claim back so a redispatch records.
     */
    private function record(TaskRun $run, Closure $recorder): void
    {
        try {
            $recorder();
        } catch (Throwable $exception) {
            TaskRun::query()->whereKey($run->id)->update(['child_outcome_recorded_at' => null]);

            throw $exception;
        }
    }

    private function claimOutcome(TaskRun $run): bool
    {
        return TaskRun::query()
            ->whereKey($run->id)
            ->whereNull('child_outcome_recorded_at')
            ->update(['child_outcome_recorded_at' => now()]) === 1;
    }

    private function childOf(?int $threadId): ?Thread
    {
        $thread = $threadId === null ? null : Thread::query()->withoutGlobalScopes()->find($threadId);

        return $thread?->isChild() ? $thread : null;
    }
}
