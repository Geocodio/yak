<?php

use App\Channels\Linear\HealthCheck as LinearChannelCheck;
use App\Channels\Slack\HealthCheck as SlackChannelCheck;
use App\Services\HealthCheck\Channel\GitHubChannelCheck;
use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\QueueWorkerCheck;
use App\Services\HealthCheck\Registry;

beforeEach(function () {
    // Clear all channel credentials so we can enable them selectively
    config([
        'yak.channels.slack.bot_token' => null,
        'yak.channels.slack.signing_secret' => null,
        'yak.channels.linear.webhook_secret' => null,
        'yak.channels.sentry.auth_token' => null,
        'yak.channels.sentry.webhook_secret' => null,
        'yak.channels.sentry.org_slug' => null,
        'yak.channels.drone.url' => null,
        'yak.channels.drone.token' => null,
        'yak.channels.github.app_id' => null,
        'yak.channels.github.private_key' => null,
        'yak.channels.github.webhook_secret' => null,
    ]);
});

it('system section includes all 6 system checks', function () {
    $ids = array_map(fn ($c) => $c->id(), app(Registry::class)->forSection(HealthSection::System));

    expect($ids)->toContain('queue-worker', 'last-task-completed', 'repositories', 'claude-cli', 'claude-auth', 'webhook-signatures');
});

it('channel section is empty when no channels are enabled', function () {
    expect(app(Registry::class)->forSection(HealthSection::Channels))->toBeEmpty();
});

it('channel section includes only enabled channels', function () {
    config([
        'yak.channels.slack.bot_token' => 'xoxb',
        'yak.channels.slack.signing_secret' => 'sig',
    ]);

    $checks = app(Registry::class)->forSection(HealthSection::Channels);
    $ids = array_map(fn ($c) => $c->id(), $checks);

    expect($ids)->toBe(['slack']);
    expect($checks[0])->toBeInstanceOf(SlackChannelCheck::class);
});

it('resolves a check by id', function () {
    expect(app(Registry::class)->get('queue-worker'))->toBeInstanceOf(QueueWorkerCheck::class);
});

it('throws when asked for an unknown id', function () {
    app(Registry::class)->get('no-such-check');
})->throws(InvalidArgumentException::class);

it('returns name for a given id without instantiating a run', function () {
    expect(app(Registry::class)->nameFor('queue-worker'))->toBe('Queue Worker');
    expect(app(Registry::class)->nameFor('linear'))->toBe('Linear');
});

it('github channel appears when github app creds are configured', function () {
    config([
        'yak.channels.github.app_id' => '123',
        'yak.channels.github.private_key' => 'fake-key',
        'yak.channels.github.webhook_secret' => 'sec',
    ]);

    $checks = app(Registry::class)->forSection(HealthSection::Channels);

    expect($checks[0])->toBeInstanceOf(GitHubChannelCheck::class);
});

it('linear channel appears when webhook secret is configured', function () {
    config(['yak.channels.linear.webhook_secret' => 'sec']);

    $checks = app(Registry::class)->forSection(HealthSection::Channels);

    expect($checks[0])->toBeInstanceOf(LinearChannelCheck::class);
});
