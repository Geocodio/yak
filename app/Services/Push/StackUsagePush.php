<?php

namespace App\Services\Push;

use App\Models\Thread;

/**
 * The one call a stack usage alert makes: the body goes to the stack owner.
 */
class StackUsagePush
{
    public function __construct(private readonly ThreadPush $push, private readonly PushRecipients $recipients) {}

    public function send(Thread $root, string $body): int
    {
        return $this->push->send(PushEvent::StackUsage, $root, $this->recipients->forStack($root), $body, "stack-{$root->id}-usage");
    }
}
