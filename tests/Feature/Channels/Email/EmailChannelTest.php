<?php

use App\Channels\ChannelRegistry;
use App\Channels\Email\EmailChannel;
use App\Channels\Email\HealthCheck;
use App\Channels\Email\InputDriver;

beforeEach(function (): void {
    config()->set('yak.channels.email', [
        'webhook_password' => 'secret',
        'inbound_address' => 'yak@example.test',
        'require_dkim' => true,
    ]);
});

it('has the expected name and required config', function (): void {
    expect((new EmailChannel)->name())->toBe('email')
        ->and((new EmailChannel)->requiredConfig())->toBe(['webhook_password', 'inbound_address']);
});

it('is enabled only when the webhook password and inbound address are set', function (): void {
    expect((new EmailChannel)->enabled())->toBeTrue();

    config()->set('yak.channels.email.webhook_password', '');

    expect((new EmailChannel)->enabled())->toBeFalse();
});

it('is registered in the channel registry', function (): void {
    expect(app(ChannelRegistry::class)->for('email'))->toBeInstanceOf(EmailChannel::class);
});

it('exposes the Postmark parser as its input driver', function (): void {
    expect((new EmailChannel)->inputDriver())->toBeInstanceOf(InputDriver::class);
});

it('has no notification, CI, or build-scanner drivers', function (): void {
    $channel = new EmailChannel;

    expect($channel->notificationDriver())->toBeNull()
        ->and($channel->ciDriver())->toBeNull()
        ->and($channel->ciBuildScanner())->toBeNull()
        ->and($channel->healthChecks()[0])->toBeInstanceOf(HealthCheck::class);
});
