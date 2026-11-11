<?php

namespace App\Services\Threads;

use App\Models\TaskRun;
use Carbon\CarbonImmutable;

/**
 * Decides whether a running turn has gone on too long. A turn has no budget;
 * it is only bounded by its assistant turn count and its wall-clock time.
 */
class RunawayGuard
{
    public const ASSISTANT_TURNS = 'assistant_turns';

    public const WALL_CLOCK = 'wall_clock';

    public function check(TaskRun $run, CarbonImmutable $now, int $assistantTurns): ?string
    {
        if ($assistantTurns >= (int) config('yak.max_turns')) {
            return self::ASSISTANT_TURNS;
        }

        $turnStartedAt = $run->agent_started_at ?? $run->getAttribute('started_at');

        if ($turnStartedAt instanceof CarbonImmutable
            && $turnStartedAt->diffInMinutes($now, true) >= (int) config('yak.threads.turn_wall_clock_minutes')) {
            return self::WALL_CLOCK;
        }

        return null;
    }

    public function message(string $reason, int $assistantTurns): string
    {
        $limit = $reason === self::ASSISTANT_TURNS
            ? "{$assistantTurns} assistant turns"
            : (int) config('yak.threads.turn_wall_clock_minutes') . ' minutes';

        return "Yak stopped this turn after {$limit}. Reply or press Continue to keep going.";
    }
}
