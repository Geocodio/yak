<?php

use App\Channels\Slack\HealthCheck as SlackChannelCheck;
use App\Services\HealthCheck\HealthSection;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['yak.channels.slack.bot_token' => 'xoxb-test']);
});

it('is a channel section check with slack identity', function () {
    $check = new SlackChannelCheck;

    expect($check->id())->toBe('slack');
    expect($check->name())->toBe('Slack');
    expect($check->section())->toBe(HealthSection::Channels);
});

it('returns Ok when auth.test responds ok', function () {
    Http::fake([
        'slack.com/api/auth.test' => Http::response(['ok' => true, 'team' => 'Yak', 'user' => 'yak-bot']),
    ]);

    $result = (new SlackChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('Yak');
    expect($result->detail)->toContain('yak-bot');
});

it('returns Error when auth.test reports ok=false', function () {
    Http::fake([
        'slack.com/api/auth.test' => Http::response(['ok' => false, 'error' => 'invalid_auth']),
    ]);

    $result = (new SlackChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('invalid_auth');
});

it('returns Error on 401', function () {
    Http::fake([
        'slack.com/api/auth.test' => Http::response('Unauthorized', 401),
    ]);

    $result = (new SlackChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('401');
});

it('returns Error on connection failure', function () {
    Http::fake([
        'slack.com/api/auth.test' => fn () => throw new ConnectionException('getaddrinfo failed'),
    ]);

    $result = (new SlackChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('Unreachable');
});
