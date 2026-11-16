<?php

namespace App\Listeners;

use App\Events\ThreadActivity;
use App\Services\Push\LiveActivities\LiveActivities;
use App\Services\Push\LiveActivities\LiveActivitySteps;
use Throwable;

/**
 * Turns the tool a thread is using into the step its Live Activity shows. A thread without a channel costs nothing.
 */
class UpdateLiveActivityStep
{
    public function __construct(private readonly LiveActivities $liveActivities) {}

    public function handle(ThreadActivity $event): void
    {
        if ($event->thread->live_activity_channel === null) {
            return;
        }

        $tool = LiveActivitySteps::toolOf($event->line);

        if ($tool === null) {
            return;
        }

        try {
            $this->liveActivities->stepChanged($event->thread, LiveActivitySteps::forTool($tool));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
