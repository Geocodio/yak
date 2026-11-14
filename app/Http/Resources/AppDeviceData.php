<?php

namespace App\Http\Resources;

use App\Models\Device;

/**
 * The response a phone gets when pairing finishes: its token once, the device and the user behind it.
 */
final class AppDeviceData
{
    /**
     * @return array{token: string, device: array{id: int, name: string, kind: string}, user: array{id: int, name: string, email: string}}
     */
    public static function pairing(Device $device, string $token): array
    {
        $user = $device->user()->firstOrFail();

        return [
            'token' => $token,
            'device' => ['id' => $device->id, 'name' => $device->name, 'kind' => $device->kind->value],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ];
    }
}
