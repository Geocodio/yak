<?php

namespace App\Http\Controllers\Api\App\Push;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateDevice;
use App\Http\Requests\Api\App\Push\UpdateLiveActivitiesRequest;
use App\Models\Device;
use App\Models\DeviceLiveActivity;
use App\Models\Thread;
use App\Services\Devices\ShortLockWait;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class LiveActivityController extends Controller
{
    /**
     * Replaces the set of threads this phone shows a Live Activity for. Ids the device's user cannot see are dropped, so every input answers 204.
     */
    public function update(UpdateLiveActivitiesRequest $request, ThreadAccess $access): Response
    {
        $device = AuthenticateDevice::device($request);
        $user = $device->user;

        $threadIds = Thread::query()->withoutGlobalScopes()
            ->whereIn('public_id', $request->validated('threads'))
            ->get()
            ->filter(fn (Thread $thread): bool => $access->canSee($user, $thread))
            ->pluck('id');

        ShortLockWait::transaction(function () use ($device, $threadIds): void {
            // The device row serializes concurrent reports from one phone.
            Device::query()->whereKey($device->id)->lockForUpdate()->first();

            DeviceLiveActivity::query()->where('device_id', $device->id)->whereNotIn('thread_id', $threadIds)->delete();

            $existing = DeviceLiveActivity::query()->where('device_id', $device->id)->pluck('thread_id');

            foreach ($threadIds->diff($existing) as $threadId) {
                DeviceLiveActivity::query()->create(['device_id' => $device->id, 'thread_id' => $threadId, 'source' => 'app', 'started_at' => now()]);
            }
        }, fn (): Throwable => new HttpException(503, 'Yak is busy with another request for this device. Try again in a few seconds.'));

        return response()->noContent();
    }
}
