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

    /** Recorded when the task was cancelled or expired before it finished. */
    case Cancelled = 'cancelled';

    /** Recorded when the task's pull request was closed without merging. */
    case PullRequestClosed = 'pr_closed';

    /** Tolerates Markdown emphasis or backticks around the line, trailing whitespace and CRLF. */
    private const string LINE_PATTERN = '/^[ \t*`]*CI-FAILURE-OUTCOME:[ \t*`]*(fixed|unverified_fix|already_fixed|not_shared|covered_by_pr #\d+|cannot_fix)[ \t*`]*\r?$/m';

    private const string MARKER_LINE_PATTERN = '/^[ \t*`]*CI-FAILURE-OUTCOME:[^\n]*(?:\n|$)/m';

    /**
     * The last outcome line wins, because the prompt quotes an example line.
     * A missing or malformed line is cannot_fix.
     */
    public static function fromSummary(?string $summary): self
    {
        $line = self::lastLine($summary);

        return $line === null ? self::CannotFix : (self::tryFrom(explode(' ', $line)[0]) ?? self::CannotFix);
    }

    /**
     * The summary without its outcome lines, for text people read.
     */
    public static function withoutLine(?string $summary): ?string
    {
        return $summary === null ? null : rtrim((string) preg_replace(self::MARKER_LINE_PATTERN, '', $summary));
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

        return array_pop($matches[1]);
    }
}
