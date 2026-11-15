<?php

namespace App\Jobs\Push;

use App\Models\DevicePushToken;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use App\Services\Push\Apns\ApnsTokenFailures;
use App\Services\Push\Widgets\WidgetReloads;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * One widget reload to one token. A delayed send opens a new one-minute window when it starts, and is dropped when a send went out after it was queued.
 */
class SendWidgetReloadJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $tokenId, public readonly bool $isDelayed, public readonly int $queuedAt = 0)
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

    public function handle(WidgetReloads $reloads, ApnsClient $client, ApnsConfig $config, ApnsPayloads $payloads, ApnsTokenFailures $failures): void
    {
        $token = DevicePushToken::query()->deliverable()->find($this->tokenId);

        if ($token === null) {
            if ($this->isDelayed) {
                $deviceId = DevicePushToken::query()->whereKey($this->tokenId)->value('device_id');

                if ($deviceId !== null) {
                    Cache::forget("widget-reloads:pending:{$deviceId}");
                }
            }

            return;
        }

        if ($this->isDelayed && $this->attempts() === 1 && $reloads->isDelayedSendStale($token, $this->queuedAt)) {
            Cache::forget("widget-reloads:pending:{$token->device_id}");

            return;
        }

        if ($this->isDelayed && $this->attempts() === 1) {
            $reloads->openWindowForDelayedSend($token);
        }

        if (! $config->isConfigured()) {
            return;
        }

        $response = $client->sendWidgetReload($token->token, $payloads->widgetReload());

        if ($response->isRetryable() && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 30);

            return;
        }

        $failures->record($token, $response);
    }
}
