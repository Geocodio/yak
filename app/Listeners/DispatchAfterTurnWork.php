<?php

namespace App\Listeners;

use App\Enums\TaskMode;
use App\Enums\TaskRunKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\FinishFollowUpTurnJob;
use App\Jobs\FinishResearchFollowUpTurnJob;
use App\Jobs\FinishResearchTurnJob;
use App\Jobs\FinishRetryTurnJob;
use App\Jobs\FinishRunYakTurnJob;
use App\Jobs\FinishThreadTurnJob;
use App\Models\TaskRun;

/**
 * Queues the after-turn job for a turn that ended. A turn the runaway guard
 * stopped is skipped (the person decides whether to continue), and so is a
 * turn whose completion was already dispatched, so a repeated event does the
 * work once.
 */
class DispatchAfterTurnWork
{
    /** @var array<string, class-string<FinishThreadTurnJob>> */
    private const array FINISHERS = [
        TaskRunKind::Initial->value => FinishRunYakTurnJob::class,
        TaskRunKind::Clarification->value => FinishRunYakTurnJob::class,
        TaskRunKind::FollowUp->value => FinishFollowUpTurnJob::class,
        TaskRunKind::Retry->value => FinishRetryTurnJob::class,
        TaskRunKind::Research->value => FinishResearchTurnJob::class,
    ];

    public function handle(TurnCompleted|TurnFailed $event): void
    {
        $run = $event->run;
        $finisher = $this->finisherFor($run);

        if ($finisher === null || $run->turn_state === TurnState::StoppedByGuard) {
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
            // The claim is given back so tick() sends the event again instead of the work being lost.
            TaskRun::query()->whereKey($run->id)->update(['completion_dispatched_at' => null]);

            throw $exception;
        }
    }

    /**
     * A follow-up turn is finished by the job of its task's mode: code
     * follow-ups push, research follow-ups answer.
     *
     * @return class-string<FinishThreadTurnJob>|null
     */
    private function finisherFor(TaskRun $run): ?string
    {
        if ($run->kind === TaskRunKind::FollowUp && $run->task?->mode === TaskMode::Research) {
            return FinishResearchFollowUpTurnJob::class;
        }

        return self::FINISHERS[$run->kind->value] ?? null;
    }
}
