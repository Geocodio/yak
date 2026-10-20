<?php

namespace App\Jobs;

use App\Channels\Slack\CoOwnerMessenger;
use App\Models\CoOwnerRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends the Slack messages of a held change. `$event` is `held`, `resolved` or `reminder`.
 */
class SendCoOwnerMessagesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CoOwnerRequest $request, public readonly string $event)
    {
        $this->onQueue('default');
    }

    public function handle(CoOwnerMessenger $messenger): void
    {
        match ($this->event) {
            'held' => $messenger->held($this->request),
            'resolved' => $messenger->resolved($this->request),
            'reminder' => $messenger->remind($this->request),
            default => throw new \InvalidArgumentException("Unknown co-owner message event {$this->event}"),
        };
    }
}
