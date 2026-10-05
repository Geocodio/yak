<?php

namespace App\Channels\Slack;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds a Yak user's Slack ID by email through `users.lookupByEmail` (bot
 * scope `users:read.email`) and stores it on the user. A miss is logged and
 * not stored, so the next event looks the person up again.
 */
class UserLookup
{
    public function slackUserIdFor(User $user): ?string
    {
        if ($user->slack_user_id !== null && $user->slack_user_id !== '') {
            return $user->slack_user_id;
        }

        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get('https://slack.com/api/users.lookupByEmail', ['email' => $user->email]);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Slack user lookup failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }

        $slackUserId = $response->json('user.id');

        if ($response->json('ok') !== true || ! is_string($slackUserId) || $slackUserId === '') {
            Log::channel('yak')->info('No Slack user matches email, direct message skipped', [
                'user_id' => $user->id,
                'error' => $response->json('error'),
            ]);

            return null;
        }

        $user->update(['slack_user_id' => $slackUserId]);

        return $slackUserId;
    }
}
