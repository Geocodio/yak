<?php

use App\Channels\Sentry\HealthCheck as SentryChannelCheck;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'yak.channels.sentry.auth_token' => 'sentry-token',
        'yak.channels.sentry.org_slug' => 'yak-org',
        'yak.channels.sentry.region_url' => 'https://us.sentry.io',
    ]);
});

it('returns Ok when org endpoint returns 200', function () {
    Http::fake([
        'us.sentry.io/api/0/organizations/yak-org/' => Http::response(['name' => 'Yak Org', 'slug' => 'yak-org']),
    ]);

    $result = (new SentryChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('Yak Org');
});

it('returns Error on 401', function () {
    Http::fake([
        'us.sentry.io/api/0/organizations/yak-org/' => Http::response('unauthorized', 401),
    ]);

    $result = (new SentryChannelCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('401');
});
