<?php

use App\Models\DeviceAuthorization;
use App\Models\User;
use App\Services\Devices\DeviceFlow;

beforeEach(function () {
    $this->headers = ['X-Yak-Protocol' => '1'];
    $this->payload = ['name' => 'work-mac', 'os' => 'darwin', 'arch' => 'arm64', 'cli_version' => '0.1.0'];
});

function startFlow($test): array
{
    return $test->postJson('/api/cli/v1/device/code', $test->payload, $test->headers)->assertOk()->json();
}

function approveFlow(array $started, ?string $name = null): void
{
    $authorization = app(DeviceFlow::class)->find($started['user_code']);
    app(DeviceFlow::class)->approve($authorization, User::factory()->create(), $name);
}

it('full login roundtrip returns the token once then expired_token', function () {
    $started = startFlow($this);

    expect($started)->toMatchArray(['expires_in' => 600, 'interval' => 5])
        ->and($started['verification_url_complete'])->toContain($started['user_code']);

    approveFlow($started);
    $this->travel(6)->seconds();

    $body = $this->postJson('/api/cli/v1/device/token', ['device_code' => $started['device_code']], $this->headers)
        ->assertOk()->json();

    expect($body['token'])->toStartWith('yakdev_')
        ->and($body['device'])->toHaveKeys(['id', 'name', 'os', 'arch'])
        ->and($body['user'])->toHaveKeys(['id', 'name', 'email']);

    $this->travel(6)->seconds();
    $this->postJson('/api/cli/v1/device/token', ['device_code' => $started['device_code']], $this->headers)
        ->assertStatus(400)->assertJson(['error' => 'expired_token']);
});

it('pending poll is 400 authorization_pending', function () {
    $started = startFlow($this);

    $this->postJson('/api/cli/v1/device/token', ['device_code' => $started['device_code']], $this->headers)
        ->assertStatus(400)->assertJson(['error' => 'authorization_pending']);
});

it('poll inside the interval is slow_down and the interval grows by 5', function () {
    $started = startFlow($this);
    $poll = fn () => $this->postJson('/api/cli/v1/device/token', ['device_code' => $started['device_code']], $this->headers);

    $poll()->assertJson(['error' => 'authorization_pending']);
    $poll()->assertJson(['error' => 'slow_down']);

    expect(DeviceAuthorization::query()->first()->poll_interval)->toBe(10);
});

it('denied is access_denied', function () {
    $started = startFlow($this);
    app(DeviceFlow::class)->deny(app(DeviceFlow::class)->find($started['user_code']));

    $this->postJson('/api/cli/v1/device/token', ['device_code' => $started['device_code']], $this->headers)
        ->assertStatus(400)->assertJson(['error' => 'access_denied']);
});

it('expired after 601 seconds is expired_token', function () {
    $started = startFlow($this);
    $this->travel(601)->seconds();

    $this->postJson('/api/cli/v1/device/token', ['device_code' => $started['device_code']], $this->headers)
        ->assertStatus(400)->assertJson(['error' => 'expired_token']);
});

it('unknown device code is expired_token', function () {
    $this->postJson('/api/cli/v1/device/token', ['device_code' => 'nope'], $this->headers)
        ->assertStatus(400)->assertJson(['error' => 'expired_token']);
});

it('rejects invalid reported fields', function (array $override) {
    $this->postJson('/api/cli/v1/device/code', $override + $this->payload, $this->headers)
        ->assertStatus(422)->assertJson(['error' => 'validation_failed']);
})->with([
    'control characters in name' => [['name' => "bad\x07name"]],
    'name over 100' => [['name' => str_repeat('a', 101)]],
    'os windows' => [['os' => 'windows']],
]);

it('11th code request in a minute from one IP is 429', function () {
    foreach (range(1, 10) as $ignored) {
        $this->postJson('/api/cli/v1/device/code', $this->payload, $this->headers)->assertOk();
    }

    $this->postJson('/api/cli/v1/device/code', $this->payload, $this->headers)->assertStatus(429)->assertJson(['error' => 'rate_limited']);
});

it('the returned token authenticates GET /me', function () {
    $started = startFlow($this);
    approveFlow($started);
    $this->travel(6)->seconds();
    $token = $this->postJson('/api/cli/v1/device/token', ['device_code' => $started['device_code']], $this->headers)->json('token');

    $this->getJson('/api/cli/v1/me', $this->headers + ['Authorization' => "Bearer {$token}"])->assertOk();
});
