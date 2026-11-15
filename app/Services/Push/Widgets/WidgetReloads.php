<?php

namespace App\Services\Push\Widgets;

use App\Enums\PushTokenKind;
use App\Jobs\Push\CheckWidgetReloadsJob;
use App\Jobs\Push\SendWidgetReloadJob;
use App\Models\DevicePushToken;
use App\Services\Push\Apns\ApnsConfig;
use Illuminate\Support\Facades\Cache;

/**
 * Sends a widget reload push when what a person's widgets show has changed, at most once a minute per device.
 * A change inside a minute that already sent is covered by one delayed send at the minute's end.
 */
final class WidgetReloads
{
    private const int WINDOW_SECONDS = 60;

    private const int CHECK_DELAY_SECONDS = 10;

    public function __construct(private readonly ApnsConfig $config) {}

    /**
     * Many changes within 10 seconds make one check.
     */
    public function somethingChanged(): void
    {
        // An installation without a widget token never queues a check. The query runs only while no check is pending.
        if (Cache::has('widget-reloads:check') || ! DevicePushToken::query()->where('kind', PushTokenKind::Widget)->exists()) {
            return;
        }

        if (Cache::add('widget-reloads:check', true, self::CHECK_DELAY_SECONDS)) {
            CheckWidgetReloadsJob::dispatch()->delay(now()->addSeconds(self::CHECK_DELAY_SECONDS));
        }
    }

    public function check(): void
    {
        if (! $this->config->isConfigured()) {
            return;
        }

        $tokens = DevicePushToken::query()
            ->deliverable()
            ->where('kind', PushTokenKind::Widget)
            ->with('device.user')
            ->get()
            ->groupBy(fn (DevicePushToken $token): int => (int) $token->device?->user_id);

        foreach ($tokens as $deviceTokens) {
            $user = $deviceTokens->first()?->device?->user;

            if ($user === null) {
                continue;
            }

            $fingerprint = WidgetFingerprint::for($user);
            $key = "widget-reloads:state:{$user->id}";

            if (Cache::get($key) === $fingerprint) {
                continue;
            }

            Cache::put($key, $fingerprint, now()->addDays(7));

            foreach ($deviceTokens as $token) {
                $this->send($token);
            }
        }
    }

    public function send(DevicePushToken $token): void
    {
        $device = $token->device_id;
        $now = now()->getTimestamp();

        if (Cache::add("widget-reloads:window:{$device}", $now, self::WINDOW_SECONDS)) {
            SendWidgetReloadJob::dispatch($token->id, false);

            return;
        }

        $secondsLeft = max(1, self::WINDOW_SECONDS - ($now - (int) Cache::get("widget-reloads:window:{$device}", $now)));

        if (Cache::add("widget-reloads:pending:{$device}", true, $secondsLeft)) {
            SendWidgetReloadJob::dispatch($token->id, true)->delay(now()->addSeconds($secondsLeft));
        }
    }

    public function openWindowForDelayedSend(DevicePushToken $token): void
    {
        Cache::put("widget-reloads:window:{$token->device_id}", now()->getTimestamp(), self::WINDOW_SECONDS);
        Cache::forget("widget-reloads:pending:{$token->device_id}");
    }
}
