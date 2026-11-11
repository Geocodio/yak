<?php

namespace App\Listeners;

use App\Models\PushDevice;
use NotificationChannels\WebPush\Events\NotificationSent;

class RecordPushSent
{
    public function handle(NotificationSent $event): void
    {
        PushDevice::query()->whereKey($event->subscription->getKey())->update([
            'last_used_at' => now(),
            'last_error' => null,
        ]);
    }
}
