<?php

use App\Channels\GitHub\GitHubChannel;
use App\Channels\GitHub\HealthCheck;
use App\Channels\GitHub\NotificationDriver;

beforeEach(function (): void {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
    ]);
});

it('has the expected name', function (): void {
    expect((new GitHubChannel)->name())->toBe('github');
});

it('declares required credentials', function (): void {
    expect((new GitHubChannel)->requiredConfig())
        ->toBe(['app_id', 'private_key', 'webhook_secret']);
});

it('is enabled when all credentials are set', function (): void {
    expect((new GitHubChannel)->enabled())->toBeTrue();
});

it('exposes notification driver and CI build scanner', function (): void {
    $channel = new GitHubChannel;

    expect($channel->notificationDriver())->toBeInstanceOf(NotificationDriver::class);
    expect($channel->ciBuildScanner())->not->toBeNull();
});

it('returns null for capabilities it does not own', function (): void {
    $channel = new GitHubChannel;

    expect($channel->inputDriver())->toBeNull();
    expect($channel->ciDriver())->toBeNull();
});

it('provides a health check', function (): void {
    expect((new GitHubChannel)->healthChecks())
        ->toHaveCount(1)
        ->sequence(fn ($check) => $check->toBeInstanceOf(HealthCheck::class));
});

it('registers canonical and legacy webhook routes', function (): void {
    $router = app('router');
    (new GitHubChannel)->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('webhooks.github'))->not->toBeNull();
    expect($router->getRoutes()->getByName('webhooks.ci.github'))->not->toBeNull();
});
