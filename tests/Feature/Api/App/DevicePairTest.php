<?php

use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
use App\Services\Devices\PairingCodes;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->headers = ['X-Yak-Protocol' => '1'];
    $this->phone = ['name' => 'Test phone 🌎', 'model' => 'iPhone19,4', 'os_version' => '27.2.1', 'app_version' => '1.0.0'];
});

it('pairs a phone with a code from a signed-in web session', function () {
    $user = User::factory()->create();
    $created = $this->actingAs($user)->postJson(route('settings.devices.pairing-codes.store'))->assertCreated();
    parse_str(parse_url($created->json('qrPayload'), PHP_URL_QUERY), $query);
    expect($created->json('qrPayload'))->toStartWith('yak://pair?url=')->and($query['url'])->toBe(config('app.url'))
        ->and($created->json('qrSvg'))->toContain('<svg')
        ->and($created->json('expiresInSeconds'))->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
    $this->app['auth']->forgetGuards();

    $paired = $this->postJson('/api/app/v1/device/pair', ['code' => $query['code']] + $this->phone, $this->headers)->assertOk();
    ContractFixture::assertMatches('app', 'device-pair', $paired->json(), ['token', 'device.id', 'user.id', 'user.name', 'user.email']);
    expect($paired->json('token'))->toStartWith('yakdev_')->and($paired->json('device.kind'))->toBe('phone');

    $this->actingAs($user)->getJson(route('settings.devices.pairing-codes.show', $created->json('id')))
        ->assertOk()->assertJson(['state' => 'paired', 'device' => ['kind' => 'phone']]);
});

it('reports waiting and then expired for an unused code', function () {
    $user = User::factory()->create();
    $id = $this->actingAs($user)->postJson(route('settings.devices.pairing-codes.store'))->json('id');

    $this->getJson(route('settings.devices.pairing-codes.show', $id))->assertJson(['state' => 'waiting', 'device' => null]);

    $this->travel(121)->seconds();

    $this->getJson(route('settings.devices.pairing-codes.show', $id))->assertJson(['state' => 'expired']);
});

it('hides another person\'s pairing code', function () {
    $pairingCode = DevicePairingCode::factory()->create();

    $this->actingAs(User::factory()->create())->getJson(route('settings.devices.pairing-codes.show', $pairingCode))->assertNotFound();
});

it('answers 400 pairing_code_invalid for an unknown code and pairing_code_expired for an old one', function () {
    $this->postJson('/api/app/v1/device/pair', ['code' => 'nope'] + $this->phone, $this->headers)
        ->assertStatus(400)->assertJsonPath('error', 'pairing_code_invalid');

    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());
    $this->travel(121)->seconds();

    $this->postJson('/api/app/v1/device/pair', ['code' => $code] + $this->phone, $this->headers)
        ->assertStatus(400)->assertJsonPath('error', 'pairing_code_expired');
    expect(Device::count())->toBe(0);
});

it('validates the pair request and ignores unknown fields', function () {
    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());

    $this->postJson('/api/app/v1/device/pair', ['code' => $code, 'name' => str_repeat('a', 101)] + $this->phone, $this->headers)
        ->assertStatus(422)->assertJsonPath('error', 'validation_failed');
    $this->postJson('/api/app/v1/device/pair', ['code' => $code, 'kind' => 'computer', 'user_id' => 99] + $this->phone, $this->headers)
        ->assertOk()->assertJsonPath('device.kind', 'phone');
});

it('refuses a pair request without the protocol header', function () {
    $this->postJson('/api/app/v1/device/pair', ['code' => 'x'] + $this->phone)->assertStatus(426);
});

it('needs a session to create a code', function () {
    $this->postJson(route('settings.devices.pairing-codes.store'))->assertUnauthorized();
    $this->post(route('settings.devices.pairing-codes.store'))->assertRedirect();
    expect(DevicePairingCode::count())->toBe(0);
});

it('needs a session to read a code state', function () {
    $pairingCode = DevicePairingCode::factory()->create();

    $this->getJson(route('settings.devices.pairing-codes.show', $pairingCode))->assertUnauthorized()->assertJsonMissingPath('state');
});

it('reports a redeemed code as paired even when its device is gone', function () {
    $pairingCode = DevicePairingCode::factory()->create(['redeemed_at' => now(), 'device_id' => null]);

    $this->actingAs($pairingCode->user)->getJson(route('settings.devices.pairing-codes.show', $pairingCode))
        ->assertOk()->assertJson(['state' => 'paired', 'device' => null]);
});

it('rate limits creating pairing codes per person', function () {
    $user = User::factory()->create();
    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->postJson(route('settings.devices.pairing-codes.store'))->assertCreated();
    }

    $this->actingAs($user)->postJson(route('settings.devices.pairing-codes.store'))->assertStatus(429);
});

it('rate limits pair requests per address', function () {
    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/app/v1/device/pair', ['code' => 'nope'] + $this->phone, $this->headers)->assertStatus(400);
    }

    $this->postJson('/api/app/v1/device/pair', ['code' => 'nope'] + $this->phone, $this->headers)
        ->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');
});

it('answers a repeat with the same request id with the same token and device', function () {
    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());
    $body = ['code' => $code, 'request_id' => '3f0b9e4a-6a53-4a0e-9a53-1c1b2d6c7f10'] + $this->phone;

    $first = $this->postJson('/api/app/v1/device/pair', $body, $this->headers)->assertOk();
    $this->travel(30)->seconds();
    $second = $this->postJson('/api/app/v1/device/pair', $body, $this->headers)->assertOk();

    expect($second->json())->toBe($first->json())->and(Device::count())->toBe(1);
});

it('refuses a repeat with another or no request id, and a second phone', function (?string $requestId) {
    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());
    $this->postJson('/api/app/v1/device/pair', ['code' => $code, 'request_id' => '3f0b9e4a-6a53-4a0e-9a53-1c1b2d6c7f10'] + $this->phone, $this->headers)->assertOk();

    $this->postJson('/api/app/v1/device/pair', ['code' => $code] + ($requestId !== null ? ['request_id' => $requestId] : []) + $this->phone, $this->headers)
        ->assertStatus(400)->assertJsonPath('error', 'pairing_code_invalid');
    expect(Device::count())->toBe(1);
})->with([null, '9c1d7a52-0b1e-4f6e-8f43-2f6d7c3e9a11']);

it('refuses a repeat after the code expired and clears the kept token', function () {
    ['code' => $code, 'pairingCode' => $pairingCode] = app(PairingCodes::class)->create(User::factory()->create());
    $body = ['code' => $code, 'request_id' => '3f0b9e4a-6a53-4a0e-9a53-1c1b2d6c7f10'] + $this->phone;
    $this->postJson('/api/app/v1/device/pair', $body, $this->headers)->assertOk();
    expect($pairingCode->fresh()->encrypted_token)->not->toBeNull();

    $this->travel(121)->seconds();

    $this->postJson('/api/app/v1/device/pair', $body, $this->headers)->assertStatus(400)->assertJsonPath('error', 'pairing_code_invalid');
    expect($pairingCode->fresh()->encrypted_token)->toBeNull();
});

it('rejects a request id that is not a UUID', function () {
    $this->postJson('/api/app/v1/device/pair', ['code' => 'x', 'request_id' => 'nope'] + $this->phone, $this->headers)
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => ['request_id']]]);
});
