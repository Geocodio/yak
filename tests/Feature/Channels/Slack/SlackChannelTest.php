<?php

use App\Channels\Slack\HealthCheck;
use App\Channels\Slack\InputDriver;
use App\Channels\Slack\NotificationDriver;
use App\Channels\Slack\SlackChannel;

beforeEach(function (): void {
    config()->set('yak.channels.slack', ['bot_token' => 'xoxb-x', 'signing_secret' => 'secret']);
});

it('has the expected name', function (): void {
    expect((new SlackChannel)->name())->toBe('slack');
});

it('declares required credentials', function (): void {
    expect((new SlackChannel)->requiredConfig())->toBe(['bot_token', 'signing_secret']);
});

it('is enabled when both credentials are set', function (): void {
    expect((new SlackChannel)->enabled())->toBeTrue();
});

it('is disabled when any credential is missing', function (): void {
    config()->set('yak.channels.slack.bot_token', '');

    expect((new SlackChannel)->enabled())->toBeFalse();
});

it('exposes input and notification drivers', function (): void {
    $channel = new SlackChannel;

    expect($channel->inputDriver())->toBeInstanceOf(InputDriver::class);
    expect($channel->notificationDriver())->toBeInstanceOf(NotificationDriver::class);
});

it('returns null for non-applicable capabilities', function (): void {
    $channel = new SlackChannel;

    expect($channel->ciDriver())->toBeNull();
    expect($channel->ciBuildScanner())->toBeNull();
});

it('provides a health check', function (): void {
    expect((new SlackChannel)->healthChecks())
        ->toHaveCount(1)
        ->sequence(fn ($check) => $check->toBeInstanceOf(HealthCheck::class));
});

it('registers webhook and interactive routes', function (): void {
    $router = app('router');
    (new SlackChannel)->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('webhooks.slack'))->not->toBeNull();
    expect($router->getRoutes()->getByName('webhooks.slack.interactive'))->not->toBeNull();
});
