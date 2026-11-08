<?php

namespace App\Services;

use App\Models\TaskRun;

/**
 * The CLI reports total_cost_usd cumulatively for a session, so a resumed
 * run's own cost is the reported total minus the latest total already
 * recorded for that session.
 */
final class SessionCost
{
    public static function incremental(?string $sessionId, float $reportedTotalUsd): float
    {
        if ($sessionId === null || $sessionId === '') {
            return $reportedTotalUsd;
        }

        $recorded = TaskRun::query()
            ->where('session_id', $sessionId)
            ->max('session_cost_total_usd');

        // A total below the recorded one means the id was reused by a fresh session.
        return $recorded === null || (float) $recorded > $reportedTotalUsd
            ? $reportedTotalUsd
            : round($reportedTotalUsd - (float) $recorded, 4);
    }
}
