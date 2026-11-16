<?php

namespace App\Providers;

use App\Models\CoOwnerRequest;
use App\Models\Device;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\ThreadView;
use App\Models\YakTask;
use App\Observers\ThreadBlockedPushObserver;
use App\Observers\ThreadLiveActivityObserver;
use App\Observers\WidgetReloadObserver;
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

        foreach ([Thread::class, TaskRun::class, ThreadView::class, ThreadShare::class, YakTask::class, CoOwnerRequest::class] as $model) {
            $model::observe(WidgetReloadObserver::class);
        }

        RateLimiter::for('app-push-test', function (Request $request): Limit {
            $device = $request->attributes->get('device');

            return Limit::perMinute(6)->by($device instanceof Device ? 'push-test:' . $device->id : 'push-test-ip:' . $request->ip());
        });
    }
}
