<?php

namespace App\Services\Push\LiveActivities;

use App\Enums\LiveActivityState;
use App\Models\Thread;
use Carbon\CarbonImmutable;

/**
 * The content state of a thread's Live Activity at one moment.
 */
final class LiveActivityContent
{
    /**
     * `turnStartedAt` is an ISO 8601 string on purpose: ActivityKit would read a number as seconds since 2001.
     * A private thread shows no step, for every viewer.
     *
     * @return array{state: string, step: string, turnStartedAt: string, isPrivate: bool}
     */
    public static function for(Thread $thread, LiveActivityState $state, string $step): array
    {
        $run = $thread->runs()->latest('id')->first();
        $startedAt = $run !== null ? $run->started_at : ($thread->created_at ?? now());
        $isPrivate = $thread->isPrivate();

        return [
            'state' => $state->value,
            'step' => $isPrivate ? '' : $step,
            'turnStartedAt' => CarbonImmutable::instance($startedAt)->utc()->toIso8601String(),
            'isPrivate' => $isPrivate,
        ];
    }
}
