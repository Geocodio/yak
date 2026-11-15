<?php

namespace App\Listeners;

use App\Events\ThreadActivity;
use App\Events\TurnStarted;
use App\Models\Thread;
use App\Services\Push\LiveActivities\LiveActivitySteps;
use App\Services\Stacks\ChildCardPublisher;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a sub-thread's card current: the tool it is using is its step, and
 * the start of a turn moves it to working.
 */
class UpdateChildStep
{
    private const CARD_SECONDS = 5;

    public function __construct(private readonly ChildCardPublisher $cards) {}

    public function handle(ThreadActivity $event): void
    {
        $tool = LiveActivitySteps::toolOf($event->line);

        if ($tool === null || ! $event->thread->isChild()) {
            return;
        }

        $event->thread->forceFill(['current_step' => $tool])->saveQuietly();

        if (Cache::add('stack:child-card:' . $event->thread->id, true, self::CARD_SECONDS)) {
            $this->cards->publish($event->thread);
        }
    }

    public function handleTurnStarted(TurnStarted $event): void
    {
        $thread = $event->run->thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($event->run->thread_id);

        if ($thread?->isChild()) {
            $this->cards->publish($thread);
        }
    }
}
