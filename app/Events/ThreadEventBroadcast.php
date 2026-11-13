<?php

namespace App\Events;

use App\Models\Thread;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One sequenced thread event on the private thread channel. Reverb rejects messages over
 * 10 KB, so a payload whose JSON exceeds MAX_BYTES goes out as a pointer the browser fetches
 * over HTTP.
 */
class ThreadEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public const MAX_BYTES = 8192;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $threadId,
        public readonly int $sequence,
        public readonly string $type,
        public readonly array $data,
        public readonly string $createdAt,
        public readonly ?string $threadPublicId = null,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('thread.' . $this->threadPublicId())];
    }

    /**
     * Channels are named by the thread's public id, the same id the page URL carries.
     */
    private function threadPublicId(): string
    {
        return $this->threadPublicId ?? (string) Thread::publicIdForKey($this->threadId);
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
        $payload = [
            'sequence' => $this->sequence,
            'type' => $this->type,
            'data' => $this->data,
            'createdAt' => $this->createdAt,
        ];

        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            return ['sequence' => $this->sequence, 'pointer' => true];
        }

        return $payload;
    }
}
