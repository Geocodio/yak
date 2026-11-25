<?php

namespace App\Channels\Slack;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a Slack channel ID to its name via `conversations.info`, cached
 * for a day. Best-effort: a missing scope, a DM or any failure gives null.
 */
class ChannelNameResolver
{
    private const CACHE_TTL_SECONDS = 86400;

    public static function resolve(?string $channelId): ?string
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($channelId === null || $channelId === '' || $token === '') {
            return null;
        }

        $cacheKey = "slack-channel-name:{$channelId}";

        /** @var string|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::withToken($token)->timeout(3)->get('https://slack.com/api/conversations.info', ['channel' => $channelId]);
            $name = $response->json('channel.name');

            if ($response->json('ok') !== true || ! is_string($name) || $name === '' || $response->json('channel.is_im') === true) {
                return null;
            }

            Cache::put($cacheKey, $name, self::CACHE_TTL_SECONDS);

            return $name;
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Slack channel name lookup failed', ['channel' => $channelId, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
