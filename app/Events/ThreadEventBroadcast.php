<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One sequenced thread event on the private thread channel.
 */
class ThreadEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $threadId,
        public readonly int $sequence,
        public readonly string $type,
        public readonly array $data,
        public readonly string $createdAt,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('thread.' . $this->threadId)];
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
        return [
            'sequence' => $this->sequence,
            'type' => $this->type,
            'data' => $this->data,
            'createdAt' => $this->createdAt,
        ];
    }
}
