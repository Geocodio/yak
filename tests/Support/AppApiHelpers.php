<?php

use App\Models\Device;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\DeviceTokens;

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: Device, 1: array<string, string>}
 */
function phoneWithHeaders(array $attributes = [], bool $isPhone = true): array
{
    $token = app(DeviceTokens::class)->generate();
    $attributes['user_id'] ??= User::factory()->create(['name' => 'Test Person', 'email' => 'person@example.test'])->id;
    $attributes += ['name' => 'Test phone'];
    $factory = $isPhone ? Device::factory()->phone() : Device::factory();
    $device = $factory->create(['token_hash' => app(DeviceTokens::class)->hash($token)] + $attributes);

    return [$device, ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"]];
}

/**
 * A new phone device for the user, as request headers.
 *
 * @return array<string, string>
 */
function phoneHeadersFor(User $user): array
{
    return phoneWithHeaders(['user_id' => $user->id])[1];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function privateThreadOf(User $owner, array $attributes = []): Thread
{
    return Thread::factory()->create($attributes + [
        'visibility' => 'private',
        'owner_user_id' => $owner->id,
        'created_by_user_id' => $owner->id,
    ]);
}

function appThreadPath(Thread $thread, string $suffix = ''): string
{
    return "/api/app/v1/threads/{$thread->public_id}{$suffix}";
}
