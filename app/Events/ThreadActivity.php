<?php

namespace App\Events;

use App\Models\ThreadEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Pushes one stored thread event to the browsers watching the thread. Payloads that would
 * not fit a websocket frame go out as a pointer and the browser fetches the event over HTTP.
 */
class ThreadActivity implements ShouldBroadcastNow
{
    public const MAX_PAYLOAD_BYTES = 8192;

    public function __construct(public ThreadEvent $event) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("threads.{$this->event->thread_id}")];
    }

    public function broadcastAs(): string
    {
        return 'thread.event';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $full = ['sequence' => $this->event->sequence, 'type' => $this->event->type, 'data' => $this->event->payload];

        if (strlen((string) json_encode($full)) <= self::MAX_PAYLOAD_BYTES) {
            return $full;
        }

        return ['sequence' => $this->event->sequence, 'type' => $this->event->type, 'pointer' => true];
    }
}
