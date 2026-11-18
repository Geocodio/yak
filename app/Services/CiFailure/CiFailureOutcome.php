<?php

namespace App\Services\CiFailure;

/**
 * How a ci-failure task ended, read from the outcome line the prompt asks the
 * agent to finish its summary with.
 */
enum CiFailureOutcome: string
{
    case Fixed = 'fixed';
    case UnverifiedFix = 'unverified_fix';
    case AlreadyFixed = 'already_fixed';
    case NotShared = 'not_shared';
    case CoveredByPullRequest = 'covered_by_pr';
    case CannotFix = 'cannot_fix';

    /** Recorded for a failed task that never produced an outcome line. */
    case Infrastructure = 'infrastructure';

    private const string LINE_PATTERN = '/^CI-FAILURE-OUTCOME: (fixed|unverified_fix|already_fixed|not_shared|covered_by_pr #\d+|cannot_fix)$/m';

    /**
     * The last outcome line wins, because the prompt quotes an example line.
     * A missing or malformed line is cannot_fix.
     */
    public static function fromSummary(?string $summary): self
    {
        $line = self::lastLine($summary);

        return $line === null ? self::CannotFix : (self::tryFrom(explode(' ', $line)[0]) ?? self::CannotFix);
    }

    public static function hasLine(?string $summary): bool
    {
        return self::lastLine($summary) !== null;
    }

    public static function coveredPullRequestNumber(?string $summary): ?int
    {
        $line = self::lastLine($summary);

        return $line !== null && preg_match('/^covered_by_pr #(\d+)$/', $line, $matches) === 1 ? (int) $matches[1] : null;
    }

    private static function lastLine(?string $summary): ?string
    {
        if ($summary === null || preg_match_all(self::LINE_PATTERN, $summary, $matches) < 1) {
            return null;
        }

        return end($matches[1]);
    }
}
