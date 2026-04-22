<?php

use App\Channels\Linear\HealthCheck;
use App\Channels\Linear\InputDriver;
use App\Channels\Linear\LinearChannel;
use App\Channels\Linear\NotificationDriver;

beforeEach(function (): void {
    config()->set('yak.channels.linear', ['webhook_secret' => 'secret']);
});

it('has the expected name', function (): void {
    expect((new LinearChannel)->name())->toBe('linear');
});

it('declares required credentials', function (): void {
    expect((new LinearChannel)->requiredConfig())->toBe(['webhook_secret']);
});

it('is enabled when the webhook secret is set', function (): void {
    expect((new LinearChannel)->enabled())->toBeTrue();
});

it('is disabled when the webhook secret is missing', function (): void {
    config()->set('yak.channels.linear.webhook_secret', '');

    expect((new LinearChannel)->enabled())->toBeFalse();
});

it('exposes input and notification drivers', function (): void {
    $channel = new LinearChannel;

    expect($channel->inputDriver())->toBeInstanceOf(InputDriver::class);
    expect($channel->notificationDriver())->toBeInstanceOf(NotificationDriver::class);
});

it('returns null for non-applicable capabilities', function (): void {
    $channel = new LinearChannel;

    expect($channel->ciDriver())->toBeNull();
    expect($channel->ciBuildScanner())->toBeNull();
});

it('provides a health check', function (): void {
    expect((new LinearChannel)->healthChecks())
        ->toHaveCount(1)
        ->sequence(fn ($check) => $check->toBeInstanceOf(HealthCheck::class));
});

it('registers the webhook route', function (): void {
    $router = app('router');
    (new LinearChannel)->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('webhooks.linear'))->not->toBeNull();
});
