<?php

namespace App\Channels\Slack;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Decides whether a Slack user may trigger Yak.
 *
 * External users (Slack Connect members of other organizations) never
 * may, wherever they write. The workspace is the one the bot token
 * belongs to (`auth.test`), so a team ID in the payload is only ever a
 * reason to reject, never a reason to trust. Every failed lookup counts
 * as external or guest, and failures are not cached.
 */
class SenderPolicy
{
    private const USER_CACHE_TTL_SECONDS = 3600;

    public function isExternal(string $userId, string ...$payloadTeamIds): bool
    {
        $workspaceTeamId = $this->workspaceTeamId();

        if ($userId === '' || $workspaceTeamId === null) {
            return true;
        }

        foreach ($payloadTeamIds as $teamId) {
            if ($teamId !== '' && $teamId !== $workspaceTeamId) {
                return true;
            }
        }

        $user = $this->user($userId);

        return $user === null || ($user['team_id'] ?? null) !== $workspaceTeamId;
    }

    /**
     * Multi-channel (`is_restricted`) and single-channel
     * (`is_ultra_restricted`) guests, plus deactivated accounts.
     */
    public function isGuest(string $userId): bool
    {
        $user = $this->user($userId);

        return $user === null
            || ($user['is_restricted'] ?? false) === true
            || ($user['is_ultra_restricted'] ?? false) === true
            || ($user['deleted'] ?? false) === true;
    }

    private function workspaceTeamId(): ?string
    {
        /** @var string|null $cached */
        $cached = Cache::get('slack-workspace-team-id');

        if ($cached !== null) {
            return $cached;
        }

        $teamId = (string) $this->call('auth.test', [])['team_id'];

        if ($teamId === '') {
            return null;
        }

        Cache::forever('slack-workspace-team-id', $teamId);

        return $teamId;
    }

    /**
     * @return array{team_id?: string, is_restricted?: bool, is_ultra_restricted?: bool, deleted?: bool}|null
     */
    private function user(string $userId): ?array
    {
        if ($userId === '') {
            return null;
        }

        $cacheKey = "slack-user-info:{$userId}";

        /** @var array{team_id?: string, is_restricted?: bool, is_ultra_restricted?: bool, deleted?: bool}|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $user = $this->call('users.info', ['user' => $userId])['user'];

        if (! is_array($user)) {
            return null;
        }

        Cache::put($cacheKey, $user, self::USER_CACHE_TTL_SECONDS);

        return $user;
    }

    /**
     * @param  array<string, string>  $query
     * @return array{team_id: mixed, user: mixed}
     */
    private function call(string $method, array $query): array
    {
        $token = (string) config('yak.channels.slack.bot_token');
        $empty = ['team_id' => null, 'user' => null];

        if ($token === '') {
            return $empty;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get("https://slack.com/api/{$method}", $query);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning("Slack {$method} lookup failed", ['error' => $e->getMessage()]);

            return $empty;
        }

        if ($response->json('ok') !== true) {
            return $empty;
        }

        return ['team_id' => $response->json('team_id'), 'user' => $response->json('user')];
    }
}
