<?php

use App\Models\CoOwnerRequest;
use App\Models\YakTask;
use Tests\Support\ContractFixture;

it('reports what the app needs at runtime', function () {
    fakeApns();
    config([
        'app.version' => '2026.11.1',
        'yak.instance_name' => 'Example Yak',
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'reverb-key',
        'broadcasting.connections.reverb.options' => ['host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https'],
    ]);
    [$device, $headers] = phoneWithHeaders();

    $response = $this->getJson('/api/app/v1/me', $headers)->assertOk()
        ->assertJsonPath('device.kind', 'phone')
        ->assertJsonPath('device.id', $device->id)
        ->assertJsonPath('user.isAdmin', false)
        ->assertJsonPath('push', ['apns' => true, 'bundleId' => 'com.example.yak', 'environment' => 'production'])
        ->assertJsonPath('realtime', ['key' => 'reverb-key', 'host' => 'ws.example.test', 'port' => 443, 'scheme' => 'https'])
        ->assertJsonPath('needsYouCount', 0)
        ->assertJsonStructure(['user' => ['id', 'name', 'email', 'initials', 'isAdmin'], 'instance' => ['name', 'url', 'version'], 'needsYouCount', 'realtime', 'terminal' => ['wsUrl'], 'attachments' => ['maxFiles', 'maxFileBytes'], 'features']);

    ContractFixture::assertMatches('app', 'me', $response->json(), ['user.id', 'device.id', 'device.pairedAt', 'instance.url', 'terminal.wsUrl']);
});

it('reports push as unavailable without a key and realtime as null without Reverb', function () {
    config(['yak.apns' => ['key_path' => null, 'key_id' => null, 'team_id' => null, 'bundle_id' => 'com.example.yak', 'environment' => null], 'broadcasting.default' => 'null', 'yak.instance_name' => null]);
    [, $headers] = phoneWithHeaders();

    $this->getJson('/api/app/v1/me', $headers)->assertOk()
        ->assertJsonPath('push.apns', false)
        ->assertJsonPath('features.liveActivities', false)
        ->assertJsonPath('realtime', null)
        ->assertJsonPath('instance.version', null);
});

it('does not report push as available when only part of the APNs config is set', function () {
    fakeApns();
    config(['yak.apns.team_id' => null]);
    [, $headers] = phoneWithHeaders();

    $this->getJson('/api/app/v1/me', $headers)->assertOk()->assertJsonPath('push.apns', false);
});

it('tells the phone the truth when the key file is broken', function () {
    $path = fakeApns();
    file_put_contents($path, 'not a key');
    [, $headers] = phoneWithHeaders();

    $this->getJson('/api/app/v1/me', $headers)->assertOk()
        ->assertJsonPath('push.apns', false)
        ->assertJsonPath('features.liveActivities', false)
        ->assertJsonPath('push.environment', 'production');
});

it('never includes the key itself', function () {
    $path = fakeApns();
    config(['broadcasting.connections.reverb.secret' => 'reverb-secret-material']);
    [, $headers] = phoneWithHeaders();

    $body = $this->getJson('/api/app/v1/me', $headers)->assertOk()->getContent();

    expect($body)->not->toContain('PRIVATE KEY')->not->toContain($path)->not->toContain('reverb-secret-material');
});

it('refuses a computer token', function () {
    [, $headers] = phoneWithHeaders(isPhone: false);

    $this->getJson('/api/app/v1/me', $headers)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('refuses a request without a token', function () {
    $this->getJson('/api/app/v1/me', ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
});

it('refuses a revoked phone', function () {
    [, $headers] = phoneWithHeaders(['revoked_at' => now()]);

    $this->getJson('/api/app/v1/me', $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');
});

it('answers 426 for an app protocol that is too old', function () {
    [, $headers] = phoneWithHeaders();

    $this->getJson('/api/app/v1/me', ['X-Yak-Protocol' => '0'] + $headers)->assertStatus(426)->assertJsonPath('error', 'protocol_too_old');
});

it('counts needs-you tasks in me', function () {
    [$device, $headers] = phoneWithHeaders();
    $task = YakTask::factory()->awaitingCoOwner()->create(['description' => 'Rotate the signing key']);
    CoOwnerRequest::factory()->create(['task_id' => $task->id, 'asked_user_ids' => [$device->user_id]]);

    $this->getJson('/api/app/v1/me', $headers)->assertOk()->assertJsonPath('needsYouCount', 1);
});
