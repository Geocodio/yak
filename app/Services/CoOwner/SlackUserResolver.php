<?php

namespace App\Services\CoOwner;

use App\Channels\Slack\SenderPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Maps a Slack user id to a Yak user: the stored `slack_user_id` first, then
 * the Slack profile email (`users.info`, scope `users:read.email`), which also
 * stores the id on the match. Only full members of the configured workspace
 * are linked: guests, Slack Connect strangers and deactivated users get null.
 */
class SlackUserResolver
{
    public function __construct(private SenderPolicy $senderPolicy) {}

    public function userFor(string $slackUserId): ?User
    {
        $user = User::query()->where('slack_user_id', $slackUserId)->first();
        if ($user !== null) {
            return $user;
        }

        $token = (string) config('yak.channels.slack.bot_token');
        if ($token === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)->timeout(5)->get('https://slack.com/api/users.info', ['user' => $slackUserId]);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        $email = $response->json('user.profile.email');
        if ($response->json('ok') !== true || ! is_string($email) || $response->json('user.is_stranger') === true) {
            return null;
        }

        if (! $this->senderPolicy->isAllowed($slackUserId)) {
            return null;
        }

        $user = User::findByEmail($email);
        $user?->update(['slack_user_id' => $slackUserId]);

        return $user;
    }
}
