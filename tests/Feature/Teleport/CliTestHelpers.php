<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * @return array<string, string>
 */
function cliHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer ' . $token,
        'X-Yak-Protocol' => '1',
        'Accept' => 'application/json',
    ];
}

/**
 * @return array{0: Device, 1: string}
 */
function makeDevice(?User $user = null): array
{
    $token = 'yakdev_' . Str::random(40);
    $device = Device::factory()->create([
        'user_id' => ($user ?? User::factory()->create())->id,
        'token_hash' => hash('sha256', $token),
    ]);

    return [$device, $token];
}
