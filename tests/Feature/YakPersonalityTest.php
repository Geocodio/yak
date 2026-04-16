<?php

use App\Ai\Agents\PersonalityAgent;
use App\Enums\NotificationType;
use App\Services\YakPersonality;
use Laravel\Ai\Ai;

beforeEach(function () {
    config()->set('ai.providers.anthropic.key', 'test-api-key');
});

it('generates a personality message via the personality agent', function () {
    Ai::fakeAgent(PersonalityAgent::class, ['Horns down, hooves moving — on it! 🐃']);

    $result = YakPersonality::generate(NotificationType::Acknowledgment, 'Fix the login bug');

    expect($result)->toBe('Horns down, hooves moving — on it! 🐃');
});

it('falls back gracefully when API key is missing', function () {
    config()->set('ai.providers.anthropic.key', '');

    $result = YakPersonality::generate(NotificationType::Acknowledgment, 'Fix the login bug');

    expect($result)->toBe('On it — Fix the login bug 🐃');
});

it('falls back when the agent throws', function () {
    Ai::fakeAgent(PersonalityAgent::class, function () {
        throw new RuntimeException('Connection timeout');
    });

    $result = YakPersonality::generate(NotificationType::Retry, 'CI failed');

    expect($result)->toBe('Retrying — CI failed 🔄');
});

it('falls back when the agent returns empty text', function () {
    Ai::fakeAgent(PersonalityAgent::class, ['']);

    $result = YakPersonality::generate(NotificationType::Error, 'Something broke');

    expect($result)->toBe('Something broke 🚨');
});

it('includes context in fallback messages that use placeholders', function () {
    config()->set('ai.providers.anthropic.key', '');

    $result = YakPersonality::generate(NotificationType::Error, 'Database connection lost');

    expect($result)->toBe('Database connection lost 🚨');
});

it('generateWithTimeout falls back when the API key is missing', function () {
    config()->set('ai.providers.anthropic.key', '');

    $result = YakPersonality::generateWithTimeout(NotificationType::Acknowledgment, 'Quick ack', timeoutSeconds: 2);

    expect($result)->toBe('On it — Quick ack 🐃');
});

it('generateWithTimeout returns the agent response when available', function () {
    Ai::fakeAgent(PersonalityAgent::class, ['Horns down, chewing through! 🐃']);

    $result = YakPersonality::generateWithTimeout(NotificationType::Acknowledgment, 'Quick ack', timeoutSeconds: 2);

    expect($result)->toBe('Horns down, chewing through! 🐃');
});

it('provides correct fallbacks for all notification types', function () {
    config()->set('ai.providers.anthropic.key', '');

    expect(YakPersonality::generate(NotificationType::Acknowledgment, 'test'))
        ->toBe('On it — test 🐃');

    expect(YakPersonality::generate(NotificationType::Progress, 'test'))
        ->toBe('test ⏳');

    expect(YakPersonality::generate(NotificationType::Clarification, 'Which repo?'))
        ->toBe('Need some input: Which repo? ❓');

    expect(YakPersonality::generate(NotificationType::Result, 'PR created'))
        ->toBe('PR created ✅');

    expect(YakPersonality::generate(NotificationType::Retry, 'test'))
        ->toBe('Retrying — test 🔄');

    expect(YakPersonality::generate(NotificationType::Error, 'Oops'))
        ->toBe('Oops 🚨');

    expect(YakPersonality::generate(NotificationType::Expiry, 'test'))
        ->toBe('This one timed out. ⏰');
});
