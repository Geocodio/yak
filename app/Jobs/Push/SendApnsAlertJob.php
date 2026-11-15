<?php

namespace App\Jobs\Push;

use App\Models\DevicePushToken;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use App\Services\Push\Apns\ApnsTokenFailures;
use App\Services\Threads\NeedsYouCount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One alert to one token. It runs after the surrounding transaction commits, so the badge it reads and the rows it finds are the committed ones.
 */
class SendApnsAlertJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array{title: string, body: string, url: string, tag: string, event: string, kind: 'thread'|'account', id: ?string}  $push
     */
    public function __construct(public readonly int $pushTokenId, public readonly array $push)
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

    public function handle(ApnsClient $client, ApnsConfig $config, ApnsPayloads $payloads, ApnsTokenFailures $failures): void
    {
        $token = DevicePushToken::query()->deliverable()->with('device')->find($this->pushTokenId);

        if ($token === null || ! $config->isConfigured()) {
            return;
        }

        $user = $token->device?->user;
        $badge = $user === null ? 0 : NeedsYouCount::for($user);

        $response = $client->sendAlert(
            $token->token,
            $payloads->alert($this->push, $badge),
            ApnsPayloads::collapseId($this->push['tag']),
        );

        if ($response->isRetryable() && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 30);

            return;
        }

        $failures->record($token, $response);
    }
}
