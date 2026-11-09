<?php

namespace App\Channels\Email;

use App\Channels\Slack\UserLookup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells a Yak user in Slack that an email claiming to come from one of
 * their addresses failed sender verification. The sender may be spoofed,
 * so this never replies by email; Slack reaches the real owner. At most one
 * message per address per hour, so a spoofing attempt cannot flood anyone.
 * Best effort: a Slack failure is reported, never thrown.
 */
class VerificationFailureNotifier
{
    public function __construct(private readonly UserLookup $users) {}

    public function notify(User $user, InboundEmail $email): void
    {
        try {
            $token = (string) config('yak.channels.slack.bot_token');

            if ($token === '' || ! $user->direct_messages_enabled) {
                return;
            }

            $slackUserId = $this->users->slackUserIdFor($user);

            if ($slackUserId === null || ! Cache::add("email-dkim-dm:{$email->fromEmail}", true, 3600)) {
                return;
            }

            $response = Http::withToken($token)->post('https://slack.com/api/chat.postMessage', [
                'channel' => $slackUserId,
                'text' => "An email claiming to be from {$email->fromEmail} failed sender verification, so Yak ignored it. Subject: {$email->subject}",
            ]);

            if ($response->json('ok') !== true) {
                Log::channel('yak')->warning('Email verification failure DM was not delivered', ['error' => $response->json('error')]);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
