<?php

namespace App\Services\Push\Apns;

use App\Jobs\Push\SendApnsAlertJob;
use App\Models\User;
use App\Services\Push\ThreadPushNotification;

/**
 * The notification channel for phones: one queued send per alert token. Recipients, toggles and presence are decided before a notification is built, so nothing here filters.
 */
final class ApnsChannel
{
    public function send(User $notifiable, ThreadPushNotification $notification): void
    {
        foreach ($notifiable->routeNotificationForApns() as $token) {
            SendApnsAlertJob::dispatch($token->id, $notification->toApns($notifiable));
        }
    }
}
