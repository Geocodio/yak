<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceData;
use App\Http\Resources\SetupData;
use App\Models\Device;
use App\Services\Devices\DeviceRevoker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $setup = $user === null ? null : SetupData::for($user);

        return Inertia::render('Settings/Devices', [
            'devices' => Inertia::defer(fn () => Device::query()
                ->where('user_id', $user?->id)
                ->active()
                ->with(['lastTeleportThread', 'ownedThreads'])
                ->latest('created_at')
                ->latest('id')
                ->get()
                ->map(fn (Device $device): array => DeviceData::from($device, $user))
                ->all(), 'devices'),
            'installCommand' => $setup['installCommand'] ?? '',
            'loginCommand' => $setup['loginCommand'] ?? '',
        ]);
    }

    public function destroy(Request $request, Device $device, DeviceRevoker $revoker): RedirectResponse
    {
        abort_if($device->user_id !== $request->user()?->id || $device->isRevoked(), 404);

        $revoker->revoke($device);

        return back()->with('success', "Revoked {$device->name}.");
    }
}
