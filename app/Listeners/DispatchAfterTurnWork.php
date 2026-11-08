<?php

namespace App\Listeners;

use App\Enums\TaskRunKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\FinishRunYakTurnJob;
use App\Models\TaskRun;

/**
 * Queues the after-turn job for a turn that ended. A turn the runaway guard
 * stopped is skipped (the person decides whether to continue), and so is a
 * turn whose completion was already dispatched, so a repeated event does the
 * work once.
 */
class DispatchAfterTurnWork
{
    /** @var array<string, class-string> */
    private const array FINISHERS = [
        TaskRunKind::Initial->value => FinishRunYakTurnJob::class,
        TaskRunKind::Clarification->value => FinishRunYakTurnJob::class,
    ];

    public function handle(TurnCompleted|TurnFailed $event): void
    {
        $run = $event->run;
        $finisher = self::FINISHERS[$run->kind->value] ?? null;

        if (! config('yak.threads.enabled') || $finisher === null || $run->turn_state === TurnState::StoppedByGuard) {
            return;
        }

        $claimed = TaskRun::query()
            ->whereKey($run->id)
            ->whereNull('completion_dispatched_at')
            ->update(['completion_dispatched_at' => now()]);

        if ($claimed === 1) {
            $finisher::dispatch($run->id);
        }
    }
}
