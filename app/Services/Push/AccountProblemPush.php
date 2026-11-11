<?php

namespace App\Services\Push;

use App\Models\ClaudeAccount;
use App\Notifications\ThreadPushNotification;
use Throwable;

/**
 * Tells an account's owner about a problem with it. No toggle and no
 * presence check; only the per-device switch applies.
 */
class AccountProblemPush
{
    public function __construct(private readonly PushPayloadFactory $payloads) {}

    public function send(ClaudeAccount $account, string $problem): void
    {
        $owner = $account->owner;

        if ($owner === null || $owner->routeNotificationForWebPush()->isEmpty()) {
            return;
        }

        try {
            $owner->notify(new ThreadPushNotification($this->payloads->forAccount($account, $problem)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
