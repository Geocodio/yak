<?php

use App\Models\Device;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Devices\DeviceTokens;
use App\Services\Threads\ThreadAccess;

beforeEach(function () {
    $this->token = app(DeviceTokens::class)->generate();
    $this->device = Device::factory()->create(['token_hash' => app(DeviceTokens::class)->hash($this->token)]);
    $this->headers = ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$this->token}"];
});

it('me returns the contract fields and bumps last_seen_at', function () {
    $this->getJson('/api/cli/v1/me', $this->headers)->assertOk()->assertJson([
        'device' => ['id' => $this->device->id, 'name' => $this->device->name, 'os' => 'darwin', 'arch' => 'arm64', 'last_teleport_thread_id' => null],
        'user' => ['id' => $this->device->user_id, 'email' => $this->device->user->email],
        'protocol' => ['current' => 1, 'min' => 1],
        'base_url' => config('app.url'),
    ])->assertJsonStructure(['device' => ['created_at', 'last_seen_at']]);

    expect($this->device->fresh()->last_seen_at)->not->toBeNull();
});

it('me hides a last teleport thread the user cannot see', function () {
    $thread = Thread::factory()->create();
    $this->device->update(['last_teleport_thread_id' => $thread->id]);
    $this->mock(ThreadAccess::class)->shouldReceive('canSee')->once()->andReturnFalse();

    $this->getJson('/api/cli/v1/me', $this->headers)->assertOk()->assertJsonPath('device.last_teleport_thread_id', null);
});

it('me with a revoked token is 401 device_revoked', function () {
    $this->device->update(['revoked_at' => now()]);

    $this->getJson('/api/cli/v1/me', $this->headers)->assertStatus(401)->assertJson(['error' => 'device_revoked']);
});

it('DELETE /device is 204 and the next call is device_revoked', function () {
    $this->deleteJson('/api/cli/v1/device', [], $this->headers)->assertNoContent();

    expect($this->device->fresh()->revoked_at)->not->toBeNull();

    $this->deleteJson('/api/cli/v1/device', [], $this->headers)->assertStatus(401)->assertJson(['error' => 'device_revoked']);
    $this->getJson('/api/cli/v1/me', $this->headers)->assertStatus(401)->assertJson(['error' => 'device_revoked']);
});

it('resolve with 21 remotes is 422', function () {
    $this->postJson('/api/cli/v1/repos/resolve', ['remotes' => array_fill(0, 21, 'https://github.com/o/n')], $this->headers)
        ->assertStatus(422)->assertJson(['error' => 'validation_failed']);
});

it('resolve keeps request order and marks unknown repos on_yak false with null fields', function () {
    Repository::factory()->create(['slug' => 'acme-web', 'github_full_name' => 'Acme/Web', 'default_branch' => 'trunk']);

    $this->postJson('/api/cli/v1/repos/resolve', ['remotes' => ['https://github.com/nobody/nothing', 'git@github.com:acme/web.git']], $this->headers)
        ->assertOk()->assertExactJson(['repos' => [
            ['remote' => 'https://github.com/nobody/nothing', 'on_yak' => false, 'repo' => null, 'default_branch' => null, 'clone_url' => null],
            ['remote' => 'git@github.com:acme/web.git', 'on_yak' => true, 'repo' => 'acme-web', 'default_branch' => 'trunk', 'clone_url' => 'https://github.com/Acme/Web.git'],
        ]]);
});
