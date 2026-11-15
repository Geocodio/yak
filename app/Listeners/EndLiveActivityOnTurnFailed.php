<?php

namespace App\Listeners;

use App\Events\TurnFailed;
use App\Services\Push\LiveActivities\LiveActivities;
use Throwable;

/**
 * Ends the thread's Live Activity when a turn failed, was stopped by the guard or was interrupted.
 */
class EndLiveActivityOnTurnFailed
{
    public function __construct(private readonly LiveActivities $liveActivities) {}

    public function handle(TurnFailed $event): void
    {
        try {
            $this->liveActivities->turnFailed($event->run);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
