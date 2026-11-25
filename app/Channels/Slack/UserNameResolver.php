<?php

namespace App\Channels\Slack;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves Slack user IDs to human-readable names and avatars via `users.info`.
 *
 * Slack webhooks only carry the user ID; the dashboard shows author
 * names and avatars on task threads, so we resolve once at task creation
 * and cache the answer. Best-effort: any failure returns null (the UI
 * falls back to source-only attribution) and is not cached, so a
 * transient API error doesn't pin a missing name for a day.
 */
class UserNameResolver
{
    private const CACHE_TTL_SECONDS = 86400;

    public static function resolve(?string $userId): ?string
    {
        return self::profile($userId)['name'] ?? null;
    }

    /**
     * The user's Slack profile picture, 72 pixels square.
     */
    public static function avatar(?string $userId): ?string
    {
        return self::profile($userId)['avatar'] ?? null;
    }

    /**
     * @return array{name: string, avatar: ?string}|null
     */
    private static function profile(?string $userId): ?array
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            return null;
        }

        $cacheKey = "slack-user-profile:{$userId}";

        /** @var array{name: string, avatar: ?string}|null $cached */
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

            $avatar = $response->json('user.profile.image_72');
            $profile = ['name' => $name, 'avatar' => is_string($avatar) && str_starts_with($avatar, 'https://') ? $avatar : null];

            Cache::put($cacheKey, $profile, self::CACHE_TTL_SECONDS);

            return $profile;
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Slack user name lookup failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
