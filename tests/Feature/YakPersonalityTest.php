<?php

use App\Enums\NotificationType;
use App\Services\YakPersonality;
use Illuminate\Support\Facades\Http;

it('generates a personality message via Haiku API', function () {
    config()->set('yak.anthropic_api_key', 'test-api-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'Horns down, hooves moving — on it! 🐃'],
            ],
        ]),
    ]);

    $result = YakPersonality::generate(NotificationType::Acknowledgment, 'Fix the login bug');

    expect($result)->toBe('Horns down, hooves moving — on it! 🐃');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.anthropic.com/v1/messages')
            && $request->header('x-api-key')[0] === 'test-api-key'
            && $request['model'] === 'claude-haiku-4-5-20251001'
            && str_contains($request['messages'][0]['content'], 'acknowledgment');
    });
});

it('falls back gracefully when API key is missing', function () {
    config()->set('yak.anthropic_api_key', '');

    $result = YakPersonality::generate(NotificationType::Acknowledgment, 'Fix the login bug');

    expect($result)->toBe('On it! 🐃');
});

it('falls back when API call fails', function () {
    config()->set('yak.anthropic_api_key', 'test-api-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response('Server Error', 500),
    ]);

    $result = YakPersonality::generate(NotificationType::Acknowledgment, 'Fix the login bug');

    expect($result)->toBe('On it! 🐃');
});

it('falls back when API returns empty text', function () {
    config()->set('yak.anthropic_api_key', 'test-api-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => ''],
            ],
        ]),
    ]);

    $result = YakPersonality::generate(NotificationType::Error, 'Something broke');

    expect($result)->toBe('Something broke 🚨');
});

it('falls back when API throws an exception', function () {
    config()->set('yak.anthropic_api_key', 'test-api-key');

    Http::fake([
        'api.anthropic.com/*' => function () {
            throw new RuntimeException('Connection timeout');
        },
    ]);

    $result = YakPersonality::generate(NotificationType::Retry, 'CI failed');

    expect($result)->toBe('Retrying. 🔄');
});

it('includes context in fallback messages that use placeholders', function () {
    config()->set('yak.anthropic_api_key', '');

    $result = YakPersonality::generate(NotificationType::Error, 'Database connection lost');

    expect($result)->toBe('Database connection lost 🚨');
});

it('provides correct fallbacks for all notification types', function () {
    config()->set('yak.anthropic_api_key', '');

    expect(YakPersonality::generate(NotificationType::Acknowledgment, 'test'))
        ->toBe('On it! 🐃');

    expect(YakPersonality::generate(NotificationType::Progress, 'test'))
        ->toBe('Still working on this. ⏳');

    expect(YakPersonality::generate(NotificationType::Clarification, 'Which repo?'))
        ->toBe('Need some input: Which repo? ❓');

    expect(YakPersonality::generate(NotificationType::Result, 'PR created'))
        ->toBe('PR created ✅');

    expect(YakPersonality::generate(NotificationType::Retry, 'test'))
        ->toBe('Retrying. 🔄');

    expect(YakPersonality::generate(NotificationType::Error, 'Oops'))
        ->toBe('Oops 🚨');

    expect(YakPersonality::generate(NotificationType::Expiry, 'test'))
        ->toBe('This one timed out. ⏰');
});

it('sends the notification type in the prompt to the API', function () {
    config()->set('yak.anthropic_api_key', 'test-api-key');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => 'Test message ✅'],
            ],
        ]),
    ]);

    YakPersonality::generate(NotificationType::Result, 'PR: https://github.com/org/repo/pull/1');

    Http::assertSent(function ($request) {
        $prompt = $request['messages'][0]['content'];

        return str_contains($prompt, 'result')
            && str_contains($prompt, 'https://github.com/org/repo/pull/1');
    });
});
