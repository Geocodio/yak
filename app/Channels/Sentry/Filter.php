<?php

namespace App\Channels\Sentry;

class Filter
{
    /**
     * CSP directive keywords that indicate a Content Security Policy violation.
     *
     * @var list<string>
     */
    private const CSP_CULPRIT_PATTERNS = [
        'font-src',
        'script-src-elem',
        'style-src-elem',
        'img-src',
        'connect-src',
        'frame-src',
        'media-src',
        'object-src',
        'script-src',
        'style-src',
        'default-src',
    ];

    /**
     * Transient infrastructure error patterns.
     *
     * @var list<string>
     */
    private const TRANSIENT_PATTERNS = [
        'RedisException',
        'Predis\\',
        'php_network_getaddresses',
        'context deadline exceeded',
        'Connection refused',
        'Operation timed out',
    ];

    /**
     * Seer actionability levels ordered by severity.
     *
     * @var array<string, int>
     */
    private const ACTIONABILITY_LEVELS = [
        'not_actionable' => 0,
        'low' => 1,
        'medium' => 2,
        'high' => 3,
    ];

    /**
     * Determine if the issue is a CSP violation based on culprit or title.
     */
    public static function isCSPViolation(string $culprit, string $title): bool
    {
        foreach (self::CSP_CULPRIT_PATTERNS as $pattern) {
            if (stripos($culprit, $pattern) !== false) {
                return true;
            }
        }

        return str_starts_with($title, 'Blocked');
    }

    /**
     * Determine if the issue is a transient infrastructure error.
     */
    public static function isTransientError(string $culprit, string $title): bool
    {
        $combined = $culprit . ' ' . $title;

        foreach (self::TRANSIENT_PATTERNS as $pattern) {
            if (stripos($combined, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the actionability level meets the minimum threshold.
     */
    public static function meetsActionability(string $actionability, string $minActionability = 'medium'): bool
    {
        $current = self::ACTIONABILITY_LEVELS[$actionability] ?? 0;
        $required = self::ACTIONABILITY_LEVELS[$minActionability] ?? 2;

        return $current >= $required;
    }

    /**
     * Determine if the event count meets the minimum threshold.
     */
    public static function meetsEventCount(int $eventCount, int $minEvents = 5): bool
    {
        return $eventCount >= $minEvents;
    }

    /**
     * Returns null if the issue should be processed, or a rejection reason string.
     *
     * Priority bypass: issues with yak-priority tag skip event count and actionability checks.
     * CSP violations and transient errors are always rejected regardless of priority.
     */
    public static function rejectionReason(
        string $culprit,
        string $title,
        string $actionability,
        int $eventCount,
        bool $hasPriorityTag,
        string $minActionability = 'medium',
        int $minEvents = 5,
    ): ?string {
        if (self::isCSPViolation($culprit, $title)) {
            return 'csp_violation';
        }

        if (self::isTransientError($culprit, $title)) {
            return 'transient_error';
        }

        // yak-priority bypasses event count and actionability checks
        if ($hasPriorityTag) {
            return null;
        }

        if (! self::meetsActionability($actionability, $minActionability)) {
            return 'low_actionability';
        }

        if (! self::meetsEventCount($eventCount, $minEvents)) {
            return 'low_event_count';
        }

        return null;
    }
}
