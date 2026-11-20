<?php

namespace App\Services\Threads;

use App\Models\TaskRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides whether a running turn has gone on too long. A turn has no budget;
 * it is bounded by its wall-clock time and, when Yak started it on its own
 * (a Sentry alert, a flaky test, a preview repair), by its assistant turn count.
 * A turn a person started or sent a message into has no turn limit.
 */
class RunawayGuard
{
    public const ASSISTANT_TURNS = 'assistant_turns';

    public const WALL_CLOCK = 'wall_clock';

    /** Task sources of work Yak starts by itself, without a person asking. */
    public const SELF_STARTED_SOURCES = ['sentry', 'flaky-test', 'preview-repair'];

    public function check(TaskRun $run, CarbonImmutable $now, int $assistantTurns): ?string
    {
        if (self::hasTurnLimit($run) && $assistantTurns >= (int) config('yak.max_turns')) {
            return self::ASSISTANT_TURNS;
        }

        $turnStartedAt = $run->agent_started_at ?? $run->getAttribute('started_at');

        if ($turnStartedAt instanceof CarbonInterface
            && $turnStartedAt->diffInMinutes($now, true) >= (int) config('yak.threads.turn_wall_clock_minutes')) {
            return self::WALL_CLOCK;
        }

        return null;
    }

    public static function hasTurnLimit(TaskRun $run): bool
    {
        return $run->driver_user_id === null && in_array($run->source, self::SELF_STARTED_SOURCES, true);
    }

    public function message(string $reason, int $assistantTurns): string
    {
        $limit = $reason === self::ASSISTANT_TURNS
            ? "{$assistantTurns} assistant turns"
            : (int) config('yak.threads.turn_wall_clock_minutes') . ' minutes';

        return "Yak stopped this turn after {$limit}. Reply or press Continue to keep going.";
    }
}
