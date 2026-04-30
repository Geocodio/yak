<?php

use App\Channels\Slack\HealthCheck;
use App\Channels\Slack\InputDriver;
use App\Channels\Slack\InteractivityHealthCheck;
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

it('provides health checks for the bot connection and the interactivity URL', function (): void {
    expect((new SlackChannel)->healthChecks())
        ->toHaveCount(2)
        ->sequence(
            fn ($check) => $check->toBeInstanceOf(HealthCheck::class),
            fn ($check) => $check->toBeInstanceOf(InteractivityHealthCheck::class),
        );
});

it('registers webhook and interactive routes', function (): void {
    $router = app('router');
    (new SlackChannel)->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('webhooks.slack'))->not->toBeNull();
    expect($router->getRoutes()->getByName('webhooks.slack.interactive'))->not->toBeNull();
});
