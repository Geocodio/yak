<?php

namespace App\Listeners;

use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Models\TaskRun;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use App\Services\Threads\TurnResult;
use Illuminate\Support\Str;

/**
 * Pushes the person who drove a thread turn: "turn finished" for a completed
 * turn, "needs you" for a failed one. Guard stops and interruptions push nothing,
 * and a failure that blocked the thread is left to the blocked-thread observer.
 */
class PushTurnFinished
{
    public function __construct(private readonly ThreadPush $push, private readonly PushRecipients $recipients) {}

    public function handleCompleted(TurnCompleted $event): void
    {
        $run = $event->run;

        if ($run->thread_id === null || $run->turn_state !== TurnState::Completed || $run->thread === null) {
            return;
        }

        $summary = trim(TurnResult::toAgentRunResult($run)->resultSummary);
        $body = $summary === '' ? 'Yak finished its turn.' : Str::limit($summary, 140);

        $this->push->send(PushEvent::TurnFinished, $run->thread, $this->recipients->forTurn($run), $body, "run-{$run->id}");
    }

    public function handleFailed(TurnFailed $event): void
    {
        $run = $event->run;

        if ($run->thread_id === null || $run->thread === null || $this->isQuiet($run)) {
            return;
        }

        $this->push->send(PushEvent::NeedsYou, $run->thread, $this->recipients->forTurn($run), 'A turn failed and needs your attention.', "run-{$run->id}");
    }

    private function isQuiet(TaskRun $run): bool
    {
        return in_array($run->turn_state, [TurnState::StoppedByGuard, TurnState::Interrupted], true)
            || $run->thread?->blocked_reason !== null;
    }
}
