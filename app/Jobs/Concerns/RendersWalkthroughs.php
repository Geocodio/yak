<?php

namespace App\Jobs\Concerns;

/**
 * The two small helpers both walkthrough render jobs need: how long an
 * attempt took, and which pull request the task's URL points at.
 */
trait RendersWalkthroughs
{
    /**
     * The PR number in a GitHub pull request URL, or null when the task
     * has no PR yet (or carries a URL in another shape).
     */
    protected function extractPrNumber(?string $prUrl): ?int
    {
        if ($prUrl === null || $prUrl === '') {
            return null;
        }

        if (preg_match('#/pull/(\d+)#', $prUrl, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /** Milliseconds since an `hrtime(true)` reading. */
    protected function elapsedMs(int $startedAtNs): int
    {
        return (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
    }
}
