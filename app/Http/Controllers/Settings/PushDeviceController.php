<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePushDeviceRequest;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\PushTestNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class PushDeviceController extends Controller
{
    public function store(StorePushDeviceRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->updatePushSubscription(
            $request->validated('endpoint'),
            $request->validated('keys.p256dh'),
            $request->validated('keys.auth'),
            $request->validated('contentEncoding'),
        );

        $device = PushDevice::query()->where('endpoint', $request->validated('endpoint'))->firstOrFail();
        $device->forceFill([
            'label' => $request->validated('label') ?? $device->label,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'is_enabled' => true,
            'last_error' => null,
        ])->save();

        return back()->with('success', 'Notifications enabled on this device.');
    }

    public function update(Request $request, PushDevice $pushDevice): RedirectResponse
    {
        $this->authorizeOwner($request, $pushDevice);

        $pushDevice->update(['is_enabled' => $request->validate(['isEnabled' => ['required', 'boolean']])['isEnabled']]);

        return back();
    }

    public function destroy(Request $request, PushDevice $pushDevice): RedirectResponse
    {
        $this->authorizeOwner($request, $pushDevice);

        $pushDevice->delete();

        return back()->with('success', 'Device removed.');
    }

    public function test(Request $request, PushDevice $pushDevice): RedirectResponse
    {
        $this->authorizeOwner($request, $pushDevice);

        Notification::route('WebPush', $pushDevice->newCollection([$pushDevice]))->notify(new PushTestNotification);

        $failure = PushDevice::query()->whereKey($pushDevice->id)->value('last_error');

        return $failure === null && PushDevice::query()->whereKey($pushDevice->id)->exists()
            ? back()->with('success', 'Test notification sent.')
            : back()->with('error', 'Test notification failed.');
    }

    private function authorizeOwner(Request $request, PushDevice $pushDevice): void
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->ownsPushSubscription($pushDevice), 404);
    }
}
