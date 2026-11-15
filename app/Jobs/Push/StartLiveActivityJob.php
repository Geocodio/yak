<?php

namespace App\Jobs\Push;

use App\Enums\LiveActivityState;
use App\Models\DeviceLiveActivity;
use App\Models\DevicePushToken;
use App\Models\Thread;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\ApnsPayloads;
use App\Services\Push\Apns\ApnsTokenFailures;
use App\Services\Push\LiveActivities\LiveActivityChannels;
use App\Services\Push\LiveActivities\LiveActivityContent;
use App\Services\Push\LiveActivities\LiveActivitySteps;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Makes sure the thread has its channel, then sends the start push to one phone token. It runs after the surrounding transaction commits.
 * It does nothing when its activity row is gone, because an end came first.
 */
class StartLiveActivityJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $activityId,
        public readonly int $pushTokenId,
        public readonly int $runId,
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

    public function handle(ApnsClient $client, ApnsConfig $config, ApnsPayloads $payloads, ApnsTokenFailures $failures, LiveActivityChannels $channels): void
    {
        $activity = DeviceLiveActivity::query()->find($this->activityId);

        if ($activity === null) {
            return;
        }

        $token = DevicePushToken::query()->deliverable()->find($this->pushTokenId);
        $thread = Thread::withoutGlobalScopes()->find($activity->thread_id);

        if ($token === null || $thread === null || ! $config->isConfigured()) {
            $activity->delete();

            return;
        }

        $channelId = $channels->ensureFor($thread);
        $content = LiveActivityContent::for($thread, LiveActivityState::Working, LiveActivitySteps::STARTING);

        $response = $client->sendLiveActivityStart(
            $token->token,
            $payloads->liveActivityStart($thread, $content, $channelId, now()->getTimestamp()),
        );

        if ($response->isRetryable() && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 30);

            return;
        }

        $failures->record($token, $response);

        if (! $response->isSuccess()) {
            $activity->delete();
        }
    }

    /**
     * The row goes, so a later turn may try again.
     */
    public function failed(?Throwable $exception): void
    {
        DeviceLiveActivity::query()->whereKey($this->activityId)->delete();
    }
}
