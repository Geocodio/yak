<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A push built from an already-guarded payload. Sent synchronously so a
 * failing device is caught by the sender, not a queue worker.
 */
class ThreadPushNotification extends Notification
{
    /**
     * @param  array{title: string, body: string, url: string, tag: string}  $payload
     */
    public function __construct(public readonly array $payload) {}

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
            ->title($this->payload['title'])
            ->body($this->payload['body'])
            ->tag($this->payload['tag'])
            ->renotify()
            ->icon('/icon-192.png')
            ->data(['url' => $this->payload['url']]);
    }
}
