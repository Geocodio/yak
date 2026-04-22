<?php

use App\Channels\Drone\BuildScanner;
use App\Channels\Drone\DroneChannel;
use App\Channels\Drone\HealthCheck;

beforeEach(function (): void {
    config()->set('yak.channels.drone', ['url' => 'https://drone.example', 'token' => 'secret']);
});

it('has the expected name', function (): void {
    expect((new DroneChannel)->name())->toBe('drone');
});

it('declares required credentials', function (): void {
    expect((new DroneChannel)->requiredConfig())->toBe(['url', 'token']);
});

it('is enabled when both credentials are set', function (): void {
    expect((new DroneChannel)->enabled())->toBeTrue();
});

it('is disabled when any credential is missing', function (): void {
    config()->set('yak.channels.drone', ['url' => 'https://drone.example', 'token' => '']);

    expect((new DroneChannel)->enabled())->toBeFalse();
});

it('exposes the CI build scanner as a capability', function (): void {
    expect((new DroneChannel)->ciBuildScanner())->toBeInstanceOf(BuildScanner::class);
});

it('returns null for non-applicable capabilities', function (): void {
    $channel = new DroneChannel;

    expect($channel->inputDriver())->toBeNull();
    expect($channel->notificationDriver())->toBeNull();
    expect($channel->ciDriver())->toBeNull();
});

it('provides a health check', function (): void {
    expect((new DroneChannel)->healthChecks())
        ->toHaveCount(1)
        ->sequence(fn ($check) => $check->toBeInstanceOf(HealthCheck::class));
});
