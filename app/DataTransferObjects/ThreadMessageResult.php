<?php

namespace App\DataTransferObjects;

final readonly class ThreadMessageResult
{
    public function __construct(public string $status, public ?string $uuid, public ?string $threadId, public ?string $notice = null) {}

    /**
     * @return array{status: string, uuid: ?string, threadId?: string, notice?: string}
     */
    public function toArray(): array
    {
        $result = ['status' => $this->status, 'uuid' => $this->uuid];

        if ($this->threadId !== null) {
            $result['threadId'] = $this->threadId;
        }

        if ($this->notice !== null) {
            $result['notice'] = $this->notice;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            is_string($stored['status'] ?? null) ? $stored['status'] : 'sent',
            is_string($stored['uuid'] ?? null) ? $stored['uuid'] : null,
            is_string($stored['threadId'] ?? null) ? $stored['threadId'] : null,
            is_string($stored['notice'] ?? null) ? $stored['notice'] : null,
        );
    }
}
