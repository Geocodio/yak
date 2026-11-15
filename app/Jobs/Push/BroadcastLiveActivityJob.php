<?php

namespace App\Jobs\Push;

use App\Models\Thread;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * One update or end to a thread's broadcast channel. It runs after the surrounding transaction commits.
 */
class BroadcastLiveActivityJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  'update'|'end'  $event
     * @param  array<string, mixed>  $contentState
     */
    public function __construct(
        public readonly int $threadId,
        public readonly string $event,
        public readonly array $contentState,
        public readonly int $timestamp,
        public readonly int $priority,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(ApnsClient $client, ApnsConfig $config, ApnsPayloads $payloads): void
    {
        $channel = Thread::withoutGlobalScopes()->whereKey($this->threadId)->value('live_activity_channel');

        if ($channel === null || ! $config->isConfigured()) {
            return;
        }

        $payload = $this->event === 'end'
            ? $payloads->liveActivityEnd($this->contentState, $this->timestamp)
            : $payloads->liveActivityUpdate($this->contentState, $this->timestamp);

        $response = $client->broadcast($channel, $payload, $this->priority);

        if ($response->isSuccess()) {
            return;
        }

        if (($response->isRetryable() || $response->isProviderTokenProblem()) && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 30);

            return;
        }

        report(new RuntimeException("APNs refused a live activity {$this->event}: status {$response->status}, reason " . ($response->reason ?? 'none') . '.'));
    }
}
