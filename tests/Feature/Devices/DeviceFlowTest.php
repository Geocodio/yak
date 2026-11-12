<?php

use App\Models\DeviceAuthorization;
use App\Models\User;
use App\Services\Devices\DeviceFlow;
use App\Services\Devices\DeviceFlowException;
use App\Services\Devices\DeviceTokens;

beforeEach(function () {
    $this->flow = app(DeviceFlow::class);
    $this->reported = ['name' => 'work-mac', 'os' => 'darwin', 'arch' => 'arm64', 'cli_version' => '0.1.0'];
});

it('start stores only hashes', function () {
    ['device_code' => $code, 'authorization' => $authorization] = $this->flow->start($this->reported, '203.0.113.7');

    expect($authorization->device_code_hash)->toBe(hash('sha256', $code))
        ->and($authorization->user_code)->toMatch('/^[BCDFGHJKLMNPQRSTVWXZ2-9]{4}-[BCDFGHJKLMNPQRSTVWXZ2-9]{4}$/')
        ->and($authorization->encrypted_token)->toBeNull()
        ->and(DeviceAuthorization::query()->where('device_code_hash', $code)->exists())->toBeFalse();
});

it('find normalises case, spaces and a missing dash', function (string $typed) {
    $authorization = DeviceAuthorization::factory()->create(['user_code' => 'WDJB-MJHT']);

    expect($this->flow->find($typed)?->id)->toBe($authorization->id);
})->with(['wdjb mjht', 'wdjbmjht', ' WDJB-MJHT ']);

it('find ignores expired and non-pending rows', function () {
    DeviceAuthorization::factory()->create(['user_code' => 'AAAA-BBBB', 'expires_at' => now()->subSecond()]);
    DeviceAuthorization::factory()->create(['user_code' => 'CCCC-DDDD', 'status' => 'denied']);

    expect($this->flow->find('AAAA-BBBB'))->toBeNull()->and($this->flow->find('CCCC-DDDD'))->toBeNull();
});

it('approve creates a device with the edited name and a verifiable token', function () {
    $user = User::factory()->create();
    ['authorization' => $authorization] = $this->flow->start($this->reported, null);

    $device = $this->flow->approve($authorization, $user, 'Home desktop');

    $authorization->refresh();
    $token = Crypt::decryptString($authorization->encrypted_token);

    expect($device->name)->toBe('Home desktop')
        ->and($device->user_id)->toBe($user->id)
        ->and($authorization->status)->toBe('approved')
        ->and($authorization->device_id)->toBe($device->id)
        ->and(app(DeviceTokens::class)->resolve($token)?->id)->toBe($device->id);
});

it('approve falls back to the reported name', function () {
    ['authorization' => $authorization] = $this->flow->start($this->reported, null);

    expect($this->flow->approve($authorization, User::factory()->create(), '  ')->name)->toBe('work-mac');
});

it('a second approve of the same code fails', function () {
    $user = User::factory()->create();
    ['authorization' => $authorization] = $this->flow->start($this->reported, null);
    $this->flow->approve($authorization, $user, null);

    expect(fn () => $this->flow->approve($authorization, $user, null))->toThrow(DeviceFlowException::class);
});
