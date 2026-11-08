<?php

namespace App\Services\Threads;

use App\Models\TaskRun;

/**
 * Decides whether a running turn has gone on too long. A turn has no budget;
 * it is only bounded by its assistant turn count and its wall-clock time.
 */
class RunawayGuard
{
    public const ASSISTANT_TURNS = 'assistant_turns';

    public const WALL_CLOCK = 'wall_clock';

    public function check(TaskRun $run): ?string
    {
        if ($run->assistant_messages >= (int) config('yak.max_turns')) {
            return self::ASSISTANT_TURNS;
        }

        if ($run->agent_started_at !== null
            && $run->agent_started_at->diffInMinutes(now(), true) >= (int) config('yak.threads.turn_wall_clock_minutes')) {
            return self::WALL_CLOCK;
        }

        return null;
    }

    public function message(TaskRun $run, string $reason): string
    {
        $limit = $reason === self::ASSISTANT_TURNS
            ? "{$run->assistant_messages} assistant turns"
            : (int) config('yak.threads.turn_wall_clock_minutes') . ' minutes';

        return "Yak stopped this turn after {$limit}. Reply or press Continue to keep going.";
    }
}
