<?php

use App\Models\Device;
use App\Services\Devices\DeviceTokens;

beforeEach(fn () => $this->tokens = new DeviceTokens);

it('generates yakdev tokens', function () {
    expect($this->tokens->generate())->toMatch('/^yakdev_[A-Za-z0-9]{40}$/');
});

it('hashes to stable lowercase hex sha256', function () {
    $hash = $this->tokens->hash('yakdev_abc');

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')->toBe($this->tokens->hash('yakdev_abc'));
});

it('resolves a device by its token only', function () {
    $token = $this->tokens->generate();
    $device = Device::factory()->create(['token_hash' => $this->tokens->hash($token)]);

    expect($this->tokens->resolve($token)?->is($device))->toBeTrue()
        ->and($this->tokens->resolve('yakdev_' . str_repeat('a', 40)))->toBeNull()
        ->and($this->tokens->resolve(null))->toBeNull()
        ->and($this->tokens->resolve(''))->toBeNull()
        ->and($this->tokens->resolve(substr($token, 7)))->toBeNull();
});

it('resolves a revoked device', function () {
    $token = $this->tokens->generate();
    $device = Device::factory()->revoked()->create(['token_hash' => $this->tokens->hash($token)]);

    expect($this->tokens->resolve($token)?->is($device))->toBeTrue();
});

it('generates user codes from the allowed alphabet', function () {
    for ($sample = 0; $sample < 500; $sample++) {
        expect($this->tokens->generateUserCode())->toMatch('/^[BCDFGHJKLMNPQRSTVWXZ2-9]{4}-[BCDFGHJKLMNPQRSTVWXZ2-9]{4}$/');
    }

    expect($this->tokens->generateCode())->toHaveLength(40);
});
