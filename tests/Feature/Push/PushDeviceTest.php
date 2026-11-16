<?php

use App\Models\PushDevice;
use App\Models\User;
use Tests\Support\FakePushEndpoint;

function pushDevicePayload(array $overrides = []): array
{
    return array_merge([
        'endpoint' => 'https://push.example.test/send/abc123',
        'keys' => ['p256dh' => 'BKey', 'auth' => 'authkey'],
        'contentEncoding' => 'aes128gcm',
        'label' => 'Laptop',
    ], $overrides);
}

it('excludes expired or disabled devices from routing', function () {
    $user = User::factory()->create();
    $enabled = PushDevice::factory()->forUser($user)->create();
    PushDevice::factory()->forUser($user)->disabled()->create();

    expect($user->routeNotificationForWebPush()->pluck('id')->all())->toBe([$enabled->id]);
});

it('stores a device and upserts on the same endpoint', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('settings.notifications.devices.store'), pushDevicePayload())->assertRedirect();
    $this->actingAs($user)->post(route('settings.notifications.devices.store'), pushDevicePayload(['label' => 'Renamed']))->assertRedirect();

    expect(PushDevice::count())->toBe(1)
        ->and(PushDevice::first()->label)->toBe('Renamed')
        ->and(PushDevice::first()->subscribable_id)->toBe($user->id);
});

it('validates device payloads', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('settings.notifications.devices.store'), pushDevicePayload(['endpoint' => 'http://insecure.test/x', 'keys' => [], 'contentEncoding' => 'nope']))
        ->assertSessionHasErrors(['endpoint', 'keys.p256dh', 'keys.auth', 'contentEncoding']);
});

it('toggles and removes a device', function () {
    $user = User::factory()->create();
    $device = PushDevice::factory()->forUser($user)->create();

    $this->actingAs($user)->patch(route('settings.notifications.devices.update', $device), ['isEnabled' => false])->assertRedirect();
    expect($device->fresh()->is_enabled)->toBeFalse();

    $this->actingAs($user)->delete(route('settings.notifications.devices.destroy', $device))->assertRedirect();
    expect(PushDevice::count())->toBe(0);
});

it('hides other people devices, admins included', function () {
    $owner = User::factory()->create();
    $device = PushDevice::factory()->forUser($owner)->create();
    $stranger = User::factory()->create();

    foreach ([
        fn () => $this->actingAs($stranger)->patch(route('settings.notifications.devices.update', $device), ['isEnabled' => false]),
        fn () => $this->actingAs($stranger)->delete(route('settings.notifications.devices.destroy', $device)),
        fn () => $this->actingAs($stranger)->post(route('settings.notifications.devices.test', $device)),
    ] as $request) {
        $request()->assertNotFound();
    }

    expect(PushDevice::count())->toBe(1);
});

it('sends an aes128gcm request with a vapid header from the test button', function () {
    $fake = FakePushEndpoint::install()->respondWith(201);
    $user = User::factory()->create();
    $device = $fake->subscription($user);

    $this->actingAs($user)->post(route('settings.notifications.devices.test', $device))->assertSessionHas('success');

    $fake->assertSentTo($device->endpoint);
    expect($fake->requests())->toHaveCount(1);
    $request = $fake->requests()[0];
    expect($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid ')
        ->and((string) $request->getBody())->not->toContain('Yak test');
    expect($device->fresh()->last_used_at)->not->toBeNull();
});

it('removes the device when the push service answers 410', function () {
    $fake = FakePushEndpoint::install()->respondWith(410);
    $user = User::factory()->create();
    $device = $fake->subscription($user);

    $this->actingAs($user)->post(route('settings.notifications.devices.test', $device))->assertSessionHas('error');

    expect(PushDevice::count())->toBe(0);
});

it('records other failures on the device', function () {
    $fake = FakePushEndpoint::install()->respondWith(500);
    $user = User::factory()->create();
    $device = $fake->subscription($user);

    $this->actingAs($user)->post(route('settings.notifications.devices.test', $device))->assertSessionHas('error');

    expect($device->fresh()->last_error)->not->toBeNull();
});
