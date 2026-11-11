<?php

namespace App\Services\Stacks;

use App\Channels\Slack\UserLookup;
use App\Mail\HeldEventMail;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadNotificationGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the owner of a stack something needs them, by Slack and email. A
 * private stack's notice carries no title and no content.
 */
class StackNotifier
{
    public function __construct(private readonly ThreadNotificationGuard $guard, private readonly UserLookup $slack) {}

    public function notifyOwner(Thread $root, string $subject): void
    {
        $owner = User::query()->find($root->effectiveOwnerUserId());

        if ($owner === null || ! $this->guard->mayNotify($owner, $root)) {
            return;
        }

        $notice = $root->isPrivate()
            ? 'A sub-thread of a private thread needs you. Open Yak to see it.'
            : "{$this->guard->pushTitle($root)}: {$subject}";

        $this->sendSlack($owner, $notice);

        try {
            Mail::to($owner->email)->send(new HeldEventMail($notice));
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Stack notice email failed', ['thread_id' => $root->id, 'error' => $exception->getMessage()]);
        }
    }

    private function sendSlack(User $owner, string $notice): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            return;
        }

        try {
            $slackUserId = $this->slack->slackUserIdFor($owner);

            if ($slackUserId !== null) {
                Http::withToken($token)->post('https://slack.com/api/chat.postMessage', ['channel' => $slackUserId, 'text' => $notice]);
            }
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Stack notice Slack message failed', ['error' => $exception->getMessage()]);
        }
    }
}
