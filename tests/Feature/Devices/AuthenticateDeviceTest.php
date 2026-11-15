<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\ClientProtocol;
use App\Models\Device;
use App\Services\Devices\DeviceTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'client.protocol:cli', 'auth.device'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)->get('/api/cli/v1/test-device', fn (Request $request) => response()->json([
        'device_id' => AuthenticateDevice::device($request)->id,
        'user_id' => $request->user()->id,
        'guard_user_id' => auth()->guard('web')->id(),
    ]));

    $this->token = app(DeviceTokens::class)->generate();
    $this->device = Device::factory()->create(['token_hash' => app(DeviceTokens::class)->hash($this->token)]);
    $this->headers = ['X-Yak-Protocol' => '1'];
});

it('rejects a missing token as unauthenticated', function () {
    $this->getJson('/api/cli/v1/test-device', $this->headers)->assertStatus(401)->assertJson(['error' => 'unauthenticated']);
});

it('rejects an unknown token as invalid_token', function () {
    $this->getJson('/api/cli/v1/test-device', $this->headers + ['Authorization' => 'Bearer yakdev_' . str_repeat('z', 40)])
        ->assertStatus(401)->assertJson(['error' => 'invalid_token']);
});

it('rejects a revoked device as device_revoked', function () {
    $this->device->update(['revoked_at' => now()]);

    $this->getJson('/api/cli/v1/test-device', $this->headers + ['Authorization' => "Bearer {$this->token}"])
        ->assertStatus(401)->assertJson(['error' => 'device_revoked']);
});

it('sets the request user and device and records last_seen_at', function () {
    $this->getJson('/api/cli/v1/test-device', $this->headers + ['Authorization' => "Bearer {$this->token}"])
        ->assertOk()
        ->assertJson(['device_id' => $this->device->id, 'user_id' => $this->device->user_id, 'guard_user_id' => $this->device->user_id]);

    expect($this->device->fresh()->last_seen_at)->not->toBeNull();
});

it('writes last_seen_at at most once per 60 seconds', function () {
    $headers = $this->headers + ['Authorization' => "Bearer {$this->token}"];

    $this->getJson('/api/cli/v1/test-device', $headers)->assertOk();
    $first = $this->device->fresh()->last_seen_at;

    $this->travel(30)->seconds();
    $this->getJson('/api/cli/v1/test-device', $headers)->assertOk();
    expect($this->device->fresh()->last_seen_at->equalTo($first))->toBeTrue();

    $this->travel(40)->seconds();
    $this->getJson('/api/cli/v1/test-device', $headers)->assertOk();
    expect($this->device->fresh()->last_seen_at->greaterThan($first))->toBeTrue();
});

it('answers 429 rate_limited with Retry-After on the 121st call in a minute', function () {
    $headers = $this->headers + ['Authorization' => "Bearer {$this->token}"];

    for ($call = 0; $call < 120; $call++) {
        $this->getJson('/api/cli/v1/test-device', $headers)->assertOk();
    }

    $this->getJson('/api/cli/v1/test-device', $headers)
        ->assertStatus(429)
        ->assertJson(['error' => 'rate_limited'])
        ->assertHeader('Retry-After');
});

it('sets no session cookie', function () {
    $response = $this->getJson('/api/cli/v1/test-device', $this->headers + ['Authorization' => "Bearer {$this->token}"])->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});

function deviceWithToken(array $attributes = []): array
{
    $token = app(DeviceTokens::class)->generate();
    $device = Device::factory()->create(['token_hash' => app(DeviceTokens::class)->hash($token)] + $attributes);

    return [$device, $token];
}

it('admits only the matching kind of device', function (string $routeKind, string $deviceKind, int $status) {
    Route::middleware(['web', 'client.protocol:app', "auth.device:{$routeKind}"])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)
        ->get('/api/app/v1/test-kind', fn () => response()->json(['ok' => true]));
    [, $token] = deviceWithToken(['kind' => $deviceKind]);

    $this->getJson('/api/app/v1/test-kind', ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"])->assertStatus($status);
})->with([['phone', 'phone', 200], ['phone', 'computer', 401], ['computer', 'phone', 401], ['computer', 'computer', 200]]);

it('answers invalid_token for the wrong kind and device_revoked for a revoked phone', function () {
    Route::middleware(['web', 'client.protocol:app', 'auth.device:phone'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)
        ->get('/api/app/v1/test-kind', fn () => response()->json(['ok' => true]));
    $headers = fn (string $token) => ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"];

    [, $computerToken] = deviceWithToken();
    $this->getJson('/api/app/v1/test-kind', $headers($computerToken))->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_token')
        ->assertJsonPath('message', 'This token does not belong to a phone. Pair this device with Yak again.');

    [, $revokedToken] = deviceWithToken(['kind' => 'phone', 'revoked_at' => now()]);
    $this->getJson('/api/app/v1/test-kind', $headers($revokedToken))->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    $this->getJson('/api/app/v1/test-kind', ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
});

it('records the app version from the User-Agent', function () {
    Route::middleware(['web', 'client.protocol:app', 'auth.device:phone'])->withoutMiddleware(ClientProtocol::WITHOUT_WEB_STATE)
        ->get('/api/app/v1/test-kind', fn () => response()->json(['ok' => true]));
    [$device, $token] = deviceWithToken(['kind' => 'phone', 'app_version' => '1.0.0']);
    $headers = ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"];

    $this->getJson('/api/app/v1/test-kind', $headers + ['User-Agent' => 'YakApp/(garbage)'])->assertOk();
    expect($device->fresh()->app_version)->toBe('1.0.0');

    $this->travel(2)->minutes();
    $this->getJson('/api/app/v1/test-kind', $headers + ['User-Agent' => 'YakApp/1.2.0 (27.2.1; iPhone19,4)'])->assertOk();
    expect($device->fresh()->app_version)->toBe('1.2.0');

    $this->travel(2)->minutes();
    $this->getJson('/api/app/v1/test-kind', $headers + ['User-Agent' => 'curl/8'])->assertOk();
    expect($device->fresh()->app_version)->toBe('1.2.0');
});

it('keeps a phone out of every CLI route', function () {
    [, $token] = deviceWithToken(['kind' => 'phone']);

    $this->getJson('/api/cli/v1/me', ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"])
        ->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
