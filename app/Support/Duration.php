<?php

namespace App\Support;

/**
 * Short, human-readable durations for individual tool calls.
 *
 * Distinct from TaskList::formatDuration(), which rounds whole-run
 * durations to minutes and floors at "1m" -- that reports a 12-second
 * command as "1m". Tool calls are usually sub-second to seconds, so they
 * need finer resolution.
 */
class Duration
{
    public static function forHumans(?int $milliseconds): string
    {
        if ($milliseconds === null || $milliseconds < 0) {
            return '—';
        }

        if ($milliseconds < 1000) {
            return "{$milliseconds}ms";
        }

        $seconds = $milliseconds / 1000;

        if ($seconds < 10) {
            return rtrim(rtrim(number_format($seconds, 1), '0'), '.') . 's';
        }

        if ($seconds < 60) {
            return round($seconds) . 's';
        }

        $minutes = intdiv((int) round($seconds), 60);
        $remainder = (int) round($seconds) % 60;

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $leftover = $minutes % 60;

            return $leftover > 0 ? "{$hours}h {$leftover}m" : "{$hours}h";
        }

        return $remainder > 0 ? "{$minutes}m {$remainder}s" : "{$minutes}m";
    }
}
