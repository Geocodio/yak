<?php

namespace App\Listeners;

use App\Models\PushDevice;
use NotificationChannels\WebPush\Events\NotificationFailed;

/**
 * Keeps the last failure on the device row so Settings can show it. Expired
 * subscriptions are already deleted by the package's report handler.
 */
class RecordPushFailure
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->report->isSubscriptionExpired()) {
            return;
        }

        PushDevice::query()->whereKey($event->subscription->getKey())->update([
            'last_error' => mb_substr($event->report->getReason(), 0, 255),
        ]);
    }
}
