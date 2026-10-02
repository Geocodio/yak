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
     * Returns null if the issue should be processed, or a rejection reason string.
     *
     * The alert rule pointed at Yak decides which issues are worth fixing;
     * this only drops classes of error that never have a code fix.
     */
    public static function rejectionReason(string $culprit, string $title): ?string
    {
        if (self::isCSPViolation($culprit, $title)) {
            return 'csp_violation';
        }

        if (self::isTransientError($culprit, $title)) {
            return 'transient_error';
        }

        return null;
    }
}
