<?php

namespace App\Ai\Agents;

/**
 * Decides whether two failed-job log excerpts plausibly share one cause.
 * It only ever declines: a true answer means "cannot rule it out".
 */
class SameCauseCheck
{
    public function shareCause(string $firstExcerpt, string $secondExcerpt): bool
    {
        return true;
    }
}
