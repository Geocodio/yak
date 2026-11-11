<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\CliProtocol;
use App\Models\Device;
use App\Services\Devices\DeviceTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'cli.protocol', 'auth.device'])->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)->get('/api/cli/v1/test-device', fn (Request $request) => response()->json([
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
