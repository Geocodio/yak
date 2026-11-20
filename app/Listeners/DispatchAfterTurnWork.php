<?php

namespace App\Listeners;

use App\Enums\TaskMode;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Jobs\AfterTurn\FinishRepositoryTurnJob;
use App\Jobs\AfterTurn\FinishResearchFollowUpTurnJob;
use App\Jobs\AfterTurn\FinishResearchTurnJob;
use App\Jobs\AfterTurn\FinishRetryTurnJob;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Jobs\AfterTurn\FinishThreadTurnJob;
use App\Models\TaskRun;
use App\Services\Push\LiveActivities\LiveActivities;
use App\Services\Threads\QueuedMessageRunner;
use App\Services\Threads\ThreadAgents;

/**
 * Queues the after-turn job for a turn that ended. A turn the runaway guard
 * stopped or a person interrupted is skipped, since nothing is pushed for it,
 * and so is a turn whose work was already queued, so a repeated event does
 * the work once. Review and setup turns have no finisher. Claude's reply to a finished agent waits for
 * the last agent: while others still run, nothing is dispatched for it.
 */
class DispatchAfterTurnWork
{
    /** @var array<string, class-string<FinishThreadTurnJob>> */
    private const array FINISHERS = [
        'initial' => FinishRunYakTurnJob::class,
        'clarification' => FinishRunYakTurnJob::class,
        'follow_up' => FinishFollowUpTurnJob::class,
        'retry' => FinishRetryTurnJob::class,
        'research' => FinishResearchTurnJob::class,
        'repository_event' => FinishRepositoryTurnJob::class,
        'agent_follow_up' => FinishFollowUpTurnJob::class,
    ];

    public function handle(TurnCompleted|TurnFailed $event): void
    {
        $run = $event->run;
        $finisher = self::FINISHERS[$run->kind->value] ?? null;

        // Claude's reply to one finished agent while others still run is not announced; the last reply is.
        if (ThreadAgents::isQuietTurn($run)) {
            // Remembered so the last agent's end can announce it if no later reply comes.
            TaskRun::query()->whereKey($run->id)->update(['quiet_unannounced_at' => now()]);
            app(QueuedMessageRunner::class)->afterTurn($run);

            return;
        }

        // This turn speaks for the quiet ones before it.
        if ($run->thread_id !== null) {
            TaskRun::query()->where('thread_id', $run->thread_id)->where('id', '<', $run->id)->whereNotNull('quiet_unannounced_at')->update(['quiet_unannounced_at' => null]);
        }

        if ($finisher === FinishFollowUpTurnJob::class && $run->task?->mode === TaskMode::Research) {
            $finisher = FinishResearchFollowUpTurnJob::class;
        }

        // A run without a finisher has no sandbox work to wait for, so its queued messages go out now.
        if ($finisher === null) {
            try {
                app(LiveActivities::class)->turnSettled($run);
            } catch (\Throwable $exception) {
                report($exception);
            }

            app(QueuedMessageRunner::class)->afterTurn($run);

            return;
        }

        if (in_array($run->turn_state, [TurnState::StoppedByGuard, TurnState::Interrupted], true)) {
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
