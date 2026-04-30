<?php

namespace App\Channels\Slack;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks two counters used by the Slack Interactivity health check:
 *
 *   - `sent`     : button-bearing clarification messages we've posted
 *   - `received` : interactive payloads that have hit the webhook
 *
 * If `sent > 0` but `received == 0` for any meaningful window, the
 * deployed Slack app most likely doesn't have an Interactivity &
 * Shortcuts request URL configured — clicks never reach the server.
 *
 * The counters use a 30-day rolling TTL on first write so the check
 * stays responsive (a stale install won't keep showing "received == 0"
 * forever after the URL gets wired up; nor will it claim "OK" for
 * months after a single ancient click).
 */
final class InteractivityTracker
{
    private const SENT_KEY = 'slack:interactive:sent';

    private const RECEIVED_KEY = 'slack:interactive:received';

    private const TTL_DAYS = 30;

    public static function recordSent(): void
    {
        self::bump(self::SENT_KEY);
    }

    public static function recordReceived(): void
    {
        self::bump(self::RECEIVED_KEY);
    }

    public static function sentCount(): int
    {
        return (int) Cache::get(self::SENT_KEY, 0);
    }

    public static function receivedCount(): int
    {
        return (int) Cache::get(self::RECEIVED_KEY, 0);
    }

    public static function reset(): void
    {
        Cache::forget(self::SENT_KEY);
        Cache::forget(self::RECEIVED_KEY);
    }

    private static function bump(string $key): void
    {
        // Cache::add only seeds the value if it doesn't already exist,
        // so the TTL gets set once on first hit and Cache::increment
        // updates the count without resetting expiry.
        Cache::add($key, 0, now()->addDays(self::TTL_DAYS));
        Cache::increment($key);
    }
}
