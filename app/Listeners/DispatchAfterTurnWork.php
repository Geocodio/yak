<?php

namespace App\Listeners;

use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Jobs\AfterTurn\FinishThreadTurnJob;
use App\Models\TaskRun;

/**
 * Queues the after-turn job for a turn that ended. A turn the runaway guard
 * stopped or a person interrupted is skipped, since nothing is pushed for it,
 * and so is a turn whose work was already queued, so a repeated event does
 * the work once. Review and setup turns have no finisher.
 */
class DispatchAfterTurnWork
{
    /** @var array<string, class-string<FinishThreadTurnJob>> */
    private const array FINISHERS = [
        'initial' => FinishRunYakTurnJob::class,
        'clarification' => FinishRunYakTurnJob::class,
    ];

    public function handle(TurnCompleted|TurnFailed $event): void
    {
        $run = $event->run;
        $finisher = self::FINISHERS[$run->kind->value] ?? null;

        if ($finisher === null || in_array($run->turn_state, [TurnState::StoppedByGuard, TurnState::Interrupted], true)) {
            return;
        }

        $claimed = TaskRun::query()
            ->whereKey($run->id)
            ->whereNull('completion_dispatched_at')
            ->update(['completion_dispatched_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $finisher::dispatch($run->id);
        } catch (\Throwable $exception) {
            // The claim is given back so a later event queues the work instead of it being lost.
            TaskRun::query()->whereKey($run->id)->update(['completion_dispatched_at' => null]);

            throw $exception;
        }
    }
}
