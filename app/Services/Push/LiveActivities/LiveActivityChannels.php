<?php

namespace App\Services\Push\LiveActivities;

use App\Exceptions\ThreadActionRefused;
use App\Jobs\Push\DeleteLiveActivityChannelJob;
use App\Models\Thread;
use App\Services\Devices\ShortLockWait;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\PushRefusals;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The one APNs broadcast channel of a thread: read it, create it once under a lock, delete it.
 */
class LiveActivityChannels
{
    public function __construct(private readonly ApnsConfig $config, private readonly ApnsClient $client) {}

    /**
     * The channel the app may use now: only when APNs is configured and the thread is open.
     */
    public function visibleFor(Thread $thread): ?string
    {
        if ($thread->closed_at !== null || ! $this->config->isConfigured()) {
            return null;
        }

        return $thread->live_activity_channel;
    }

    /**
     * Returns the stored channel, or creates one at Apple while holding a per-thread lock and stores it before returning.
     * A request that waited on the lock reads the column again, so concurrent callers create one channel.
     * The lock lives 30 seconds: the worst case is two 5-second Apple calls plus a provider token renewal wait, and the lock must outlive it so a second caller never sees an empty column while the first still creates.
     *
     * @throws HttpException 503 when the lock wait runs out, Apple is slow or the channel limit is reached
     * @throws ThreadActionRefused when Apple refuses the channel for a reason a retry will not change
     */
    public function ensureFor(Thread $thread, bool $repeatAfterRenewal = true): string
    {
        if (filled($thread->live_activity_channel)) {
            return $thread->live_activity_channel;
        }

        try {
            return Cache::lock("live-activity-channel:{$thread->id}", 30)->block(
                ShortLockWait::LOCK_WAIT_SECONDS,
                fn (): string => $this->createOnce($thread, $repeatAfterRenewal),
            );
        } catch (LockTimeoutException) {
            throw new HttpException(503, 'Yak is still setting up live updates for this thread. Try again in a moment.');
        }
    }

    public function deleteLater(Thread $thread): void
    {
        $job = $this->deleteJobFor($thread);

        if ($job !== null) {
            dispatch($job);
        }
    }

    public function deleteJobFor(Thread $thread): ?DeleteLiveActivityChannelJob
    {
        return filled($thread->live_activity_channel) ? new DeleteLiveActivityChannelJob($thread->id, $thread->live_activity_channel) : null;
    }

    private function createOnce(Thread $thread, bool $repeatAfterRenewal): string
    {
        $existing = Thread::withoutGlobalScopes()->whereKey($thread->id)->value('live_activity_channel');

        if (filled($existing)) {
            $thread->setAttribute('live_activity_channel', $existing);

            return $existing;
        }

        $response = $this->client->createChannel($repeatAfterRenewal);

        if ($response->isSuccess() && filled($response->channelId)) {
            $thread->forceFill(['live_activity_channel' => $response->channelId])->saveQuietly();

            return $response->channelId;
        }

        if ($response->status === 429 || $response->reason === 'TooManyChannels') {
            throw new HttpException(503, 'This Yak has used up its live update channels for now. Try again later.');
        }

        if ($response->isRetryable() || $response->isProviderTokenProblem()) {
            throw new HttpException(503, 'Apple did not answer in time. Try again in a minute.');
        }

        throw PushRefusals::rejected($response->reason ?? (string) $response->status);
    }
}
