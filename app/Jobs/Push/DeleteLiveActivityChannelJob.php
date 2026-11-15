<?php

namespace App\Jobs\Push;

use App\Models\Thread;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes a thread's channel at Apple, then clears the column only while it still holds that channel.
 */
class DeleteLiveActivityChannelJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $threadId, public readonly string $channelId)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(ApnsClient $client, ApnsConfig $config): void
    {
        if ($config->isConfigured()) {
            $response = $client->deleteChannel($this->channelId);

            if (! $response->isSuccess()) {
                if ($response->isRetryable() || $response->isProviderTokenProblem()) {
                    $this->release(5);

                    return;
                }

                return;
            }
        }

        Thread::withoutGlobalScopes()
            ->whereKey($this->threadId)
            ->where('live_activity_channel', $this->channelId)
            ->update(['live_activity_channel' => null]);
    }
}
