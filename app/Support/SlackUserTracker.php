<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Tracks which Slack users have seen Yak respond to them, so we only
 * show the first-timer intro block once. Cache-backed so there's no
 * schema change — intro is a transient nudge, not a durable fact.
 */
class SlackUserTracker
{
    /** Year TTL — long enough that "has this user ever seen Yak" doesn't re-fire for normal users. */
    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 365;

    /**
     * Record that a user has now seen Yak. Returns true only on the
     * first call for a given user — callers use that signal to attach
     * the one-shot intro block to the current message.
     */
    public static function markSeen(string $slackUserId): bool
    {
        if ($slackUserId === '') {
            return false;
        }

        $key = self::cacheKey($slackUserId);

        if (Cache::has($key)) {
            return false;
        }

        Cache::put($key, true, self::CACHE_TTL_SECONDS);

        return true;
    }

    public static function hasSeen(string $slackUserId): bool
    {
        if ($slackUserId === '') {
            return false;
        }

        return Cache::has(self::cacheKey($slackUserId));
    }

    private static function cacheKey(string $slackUserId): string
    {
        return "yak:slack-user-seen:{$slackUserId}";
    }
}
