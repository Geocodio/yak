<?php

namespace App\Services\Push;

use App\Services\Push\Apns\ApnsChannel;
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
     * @param  array{title: string, body: string, url: string, tag: string, event: string, kind: 'thread'|'account', id: ?string}  $payload
     */
    public function __construct(public readonly array $payload) {}

    /**
     * Web Push for a person with an enabled browser device, APNs for a person with an alert token, both when both.
     *
     * @return list<class-string>
     */
    public function via(mixed $notifiable): array
    {
        $channels = [];

        if ($notifiable->routeNotificationForWebPush()->isNotEmpty()) {
            $channels[] = WebPushChannel::class;
        }

        if ($notifiable->routeNotificationForApns()->isNotEmpty()) {
            $channels[] = ApnsChannel::class;
        }

        return $channels;
    }

    /**
     * @return array{title: string, body: string, url: string, tag: string, event: string, kind: 'thread'|'account', id: ?string}
     */
    public function toApns(mixed $notifiable): array
    {
        return $this->payload;
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
