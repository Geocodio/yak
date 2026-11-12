<?php

namespace App\Services\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The fixed message behind the per-device test button. Sent synchronously.
 */
class PushTestNotification extends Notification
{
    /**
     * @return list<class-string>
     */
    public function via(mixed $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Yak test')
            ->body('Notifications work on this device.')
            ->tag('yak-test')
            ->data(['url' => url('/settings/notifications')]);
    }
}
