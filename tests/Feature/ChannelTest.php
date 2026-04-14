<?php

use App\Channel;

/*
|--------------------------------------------------------------------------
| Channel::enabled()
|--------------------------------------------------------------------------
*/

test('channel is enabled when all required credentials are present', function () {
    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => 'xoxb-test-token',
        'signing_secret' => 'test-secret',
    ]);

    $channel = new Channel('slack');

    expect($channel->enabled())->toBeTrue();
});

test('channel is disabled when a required credential is missing', function () {
    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => null,
        'signing_secret' => 'test-secret',
    ]);

    $channel = new Channel('slack');

    expect($channel->enabled())->toBeFalse();
});

test('channel is disabled when a required credential is empty string', function () {
    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => '',
        'signing_secret' => 'test-secret',
    ]);

    $channel = new Channel('slack');

    expect($channel->enabled())->toBeFalse();
});

test('channel is disabled when config is missing entirely', function () {
    config()->set('yak.channels.nonexistent', null);

    $channel = new Channel('nonexistent');

    expect($channel->enabled())->toBeTrue();
});

test('all channels are disabled by default without env credentials', function () {
    foreach (['slack', 'linear', 'sentry', 'drone', 'github'] as $name) {
        $channel = new Channel($name);

        expect($channel->enabled())->toBeFalse("Expected {$name} to be disabled");
    }
});

test('linear is enabled when webhook_secret is set', function () {
    config()->set('yak.channels.linear', [
        'driver' => 'linear',
        'webhook_secret' => 'whsec_test',
    ]);

    $channel = new Channel('linear');

    expect($channel->enabled())->toBeTrue();
});

test('sentry requires auth_token, webhook_secret, and org_slug', function () {
    config()->set('yak.channels.sentry', [
        'driver' => 'sentry',
        'auth_token' => 'sntrys_test',
        'webhook_secret' => 'whsec_test',
        'org_slug' => 'my-org',
        'region_url' => 'https://us.sentry.io',
        'min_events' => 5,
        'min_actionability' => 'medium',
    ]);

    $channel = new Channel('sentry');

    expect($channel->enabled())->toBeTrue();
});

test('sentry is disabled without org_slug', function () {
    config()->set('yak.channels.sentry', [
        'driver' => 'sentry',
        'auth_token' => 'sntrys_test',
        'webhook_secret' => 'whsec_test',
        'org_slug' => null,
        'region_url' => 'https://us.sentry.io',
        'min_events' => 5,
        'min_actionability' => 'medium',
    ]);

    $channel = new Channel('sentry');

    expect($channel->enabled())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Channel::config()
|--------------------------------------------------------------------------
*/

test('config returns the channel configuration array', function () {
    $channel = new Channel('slack');

    $config = $channel->config();

    expect($config)->toBeArray()
        ->toHaveKey('driver', 'slack')
        ->toHaveKey('bot_token')
        ->toHaveKey('signing_secret');
});

test('config returns empty array for unknown channel', function () {
    $channel = new Channel('unknown');

    expect($channel->config())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Channel::driver()
|--------------------------------------------------------------------------
*/

test('driver returns the driver name from config', function () {
    $channel = new Channel('slack');

    expect($channel->driver())->toBe('slack');
});

test('driver falls back to channel name if driver key is missing', function () {
    config()->set('yak.channels.custom', ['some_key' => 'value']);

    $channel = new Channel('custom');

    expect($channel->driver())->toBe('custom');
});

test('driver returns channel name for unknown channel', function () {
    $channel = new Channel('foobar');

    expect($channel->driver())->toBe('foobar');
});
