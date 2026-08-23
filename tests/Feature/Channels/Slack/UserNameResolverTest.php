<?php

use App\Channels\Slack\UserNameResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test-token');
    Cache::flush();
});

it('resolves a Slack user id to a display name via users.info', function () {
    Http::fake([
        'slack.com/api/users.info*' => Http::response([
            'ok' => true,
            'user' => ['real_name' => 'Mathias Hansen', 'profile' => ['display_name' => 'mathias']],
        ]),
    ]);

    expect(UserNameResolver::resolve('U123'))->toBe('mathias');
});

it('falls back to real_name when display_name is empty', function () {
    Http::fake([
        'slack.com/api/users.info*' => Http::response([
            'ok' => true,
            'user' => ['real_name' => 'Mathias Hansen', 'profile' => ['display_name' => '']],
        ]),
    ]);

    expect(UserNameResolver::resolve('U123'))->toBe('Mathias Hansen');
});

it('caches lookups so repeat resolves make one API call', function () {
    Http::fake([
        'slack.com/api/users.info*' => Http::response([
            'ok' => true,
            'user' => ['real_name' => 'Mathias Hansen', 'profile' => []],
        ]),
    ]);

    UserNameResolver::resolve('U123');
    UserNameResolver::resolve('U123');

    Http::assertSentCount(1);
});

it('returns null for a null/empty id or a failed lookup without caching the failure', function () {
    Http::fake(['slack.com/api/users.info*' => Http::response(['ok' => false, 'error' => 'user_not_found'])]);

    expect(UserNameResolver::resolve(null))->toBeNull()
        ->and(UserNameResolver::resolve(''))->toBeNull()
        ->and(UserNameResolver::resolve('U404'))->toBeNull();
});
