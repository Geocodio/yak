<?php

namespace App\Listeners;

use App\Events\TurnStarted;
use App\Services\Push\LiveActivities\LiveActivities;
use Throwable;

/**
 * Starts a Live Activity on the driver's phones when a turn begins somewhere other than the phone.
 */
class StartLiveActivities
{
    public function __construct(private readonly LiveActivities $liveActivities) {}

    public function handle(TurnStarted $event): void
    {
        try {
            $this->liveActivities->turnStarted($event->run);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
