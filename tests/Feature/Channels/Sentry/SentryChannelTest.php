<?php

use App\Channels\Sentry\HealthCheck;
use App\Channels\Sentry\InputDriver;
use App\Channels\Sentry\SentryChannel;

beforeEach(function (): void {
    config()->set('yak.channels.sentry', [
        'auth_token' => 'token',
        'webhook_secret' => 'secret',
        'org_slug' => 'acme',
    ]);
});

it('has the expected name', function (): void {
    expect((new SentryChannel)->name())->toBe('sentry');
});

it('declares required credentials', function (): void {
    expect((new SentryChannel)->requiredConfig())
        ->toBe(['auth_token', 'webhook_secret', 'org_slug']);
});

it('is enabled when all credentials are set', function (): void {
    expect((new SentryChannel)->enabled())->toBeTrue();
});

it('is disabled when any credential is missing', function (): void {
    config()->set('yak.channels.sentry.org_slug', '');

    expect((new SentryChannel)->enabled())->toBeFalse();
});

it('exposes the input driver as a capability', function (): void {
    expect((new SentryChannel)->inputDriver())->toBeInstanceOf(InputDriver::class);
});

it('returns null for non-applicable capabilities', function (): void {
    $channel = new SentryChannel;

    expect($channel->notificationDriver())->toBeNull();
    expect($channel->ciDriver())->toBeNull();
    expect($channel->ciBuildScanner())->toBeNull();
});

it('provides a health check', function (): void {
    expect((new SentryChannel)->healthChecks())
        ->toHaveCount(1)
        ->sequence(fn ($check) => $check->toBeInstanceOf(HealthCheck::class));
});

it('registers the webhook route', function (): void {
    $router = app('router');
    (new SentryChannel)->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('webhooks.sentry'))->not->toBeNull();
});
