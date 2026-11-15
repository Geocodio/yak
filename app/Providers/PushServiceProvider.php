<?php

namespace App\Providers;

use App\Models\Device;
use App\Models\Thread;
use App\Observers\ThreadBlockedPushObserver;
use App\Observers\ThreadLiveActivityObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PushServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Thread::observe(ThreadBlockedPushObserver::class);
        Thread::observe(ThreadLiveActivityObserver::class);

        RateLimiter::for('app-push-test', function (Request $request): Limit {
            $device = $request->attributes->get('device');

            return Limit::perMinute(6)->by($device instanceof Device ? 'push-test:' . $device->id : 'push-test-ip:' . $request->ip());
        });
    }
}
