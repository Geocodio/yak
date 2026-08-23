<?php

namespace App\Channels\Slack;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves Slack user IDs to human-readable names via `users.info`.
 *
 * Slack webhooks only carry the user ID; the dashboard shows author
 * names on task threads, so we resolve once at task creation and cache
 * the answer. Best-effort: any failure returns null (the UI falls back
 * to source-only attribution) and is not cached, so a transient API
 * error doesn't pin a missing name for a day.
 */
class UserNameResolver
{
    private const CACHE_TTL_SECONDS = 86400;

    public static function resolve(?string $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            return null;
        }

        $cacheKey = "slack-user-name:{$userId}";

        /** @var string|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get('https://slack.com/api/users.info', ['user' => $userId]);

            if (! $response->successful() || $response->json('ok') !== true) {
                return null;
            }

            $name = (string) ($response->json('user.profile.display_name') ?: $response->json('user.real_name') ?: $response->json('user.name') ?: '');

            if ($name === '') {
                return null;
            }

            Cache::put($cacheKey, $name, self::CACHE_TTL_SECONDS);

            return $name;
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Slack user name lookup failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
