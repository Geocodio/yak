<?php

use App\Services\ClaudeAccounts\AccountFailureClassifier;
use App\Services\ClaudeAccounts\AccountFailureKind;

dataset('classified lines', [
    'assistant authentication_failed' => [['type' => 'assistant', 'error' => 'authentication_failed'], AccountFailureKind::AuthFailed],
    'assistant 401' => [['type' => 'assistant', 'api_error_status' => 401], AccountFailureKind::AuthFailed],
    'error result 401 without assistant error' => [['type' => 'result', 'is_error' => true, 'api_error_status' => 401], AccountFailureKind::AuthFailed],
    'assistant usage limit' => [['type' => 'assistant', 'api_error_status' => 429, 'api_error' => 'usage_limit_reached'], AccountFailureKind::UsageLimit],
    'result usage limit with success subtype' => [['type' => 'result', 'subtype' => 'success', 'is_error' => true, 'api_error_status' => 429, 'api_error' => 'usage_limit_reached'], AccountFailureKind::UsageLimit],
    'assistant transient 429' => [['type' => 'assistant', 'api_error_status' => 429], AccountFailureKind::TransientRateLimit],
    'result transient 429 with success subtype' => [['type' => 'result', 'subtype' => 'success', 'is_error' => true, 'api_error_status' => 429], AccountFailureKind::TransientRateLimit],
]);

dataset('benign lines', [
    'api_retry 429' => [['type' => 'system', 'subtype' => 'api_retry', 'error_status' => 429, 'api_error_status' => 429]],
    'api_retry 401' => [['type' => 'system', 'subtype' => 'api_retry', 'error_status' => 401, 'error' => 'authentication_failed']],
    'success result' => [['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'done']],
    'success result carrying 429 without is_error' => [['type' => 'result', 'subtype' => 'success', 'api_error_status' => 429]],
    'error result without status' => [['type' => 'result', 'is_error' => true, 'subtype' => 'error_during_execution']],
    'assistant text' => [['type' => 'assistant', 'message' => ['content' => []]]],
    'unknown 500' => [['type' => 'result', 'is_error' => true, 'api_error_status' => 500]],
    'lifecycle' => [['type' => 'command_lifecycle', 'state' => 'started']],
]);

it('classifies failure lines', function (array $line, AccountFailureKind $kind) {
    expect((new AccountFailureClassifier)->classify($line)?->kind)->toBe($kind);
})->with('classified lines');

it('ignores benign lines', function (array $line) {
    expect((new AccountFailureClassifier)->classify($line))->toBeNull();
})->with('benign lines');

it('reads rateLimitFrom only for rejected rate limit events', function () {
    $event = fn (string $status) => ['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => $status, 'resetsAt' => 1794136119, 'rateLimitType' => 'five_hour']];

    $parsed = AccountFailureClassifier::rateLimitFrom($event('rejected'));

    expect($parsed['resetsAt']->getTimestamp())->toBe(1794136119)
        ->and($parsed['type'])->toBe('five_hour')
        ->and(AccountFailureClassifier::rateLimitFrom($event('allowed')))->toBeNull()
        ->and(AccountFailureClassifier::rateLimitFrom(['type' => 'system']))->toBeNull();
});

it('flags usage-limit fixture lines and the reset time', function () {
    $classifier = new AccountFailureClassifier;
    $lines = runnerStreamLines('usage-limit');

    $kinds = collect($lines)->filter(fn ($line) => in_array($line['type'], ['assistant', 'result'], true))
        ->map(fn ($line) => $classifier->classify($line)?->kind)->values()->all();
    $rejected = collect($lines)->map(AccountFailureClassifier::rateLimitFrom(...))->filter()->first();

    expect($kinds)->toBe([AccountFailureKind::UsageLimit, AccountFailureKind::UsageLimit])
        ->and($rejected['resetsAt']->getTimestamp())->toBe(1794136119)
        ->and($rejected['type'])->toBe('five_hour');
});

it('flags the authentication-failed fixture', function () {
    $classifier = new AccountFailureClassifier;
    $kinds = collect(runnerStreamLines('authentication-failed'))->map(fn ($line) => $classifier->classify($line)?->kind)->filter()->unique()->values()->all();

    expect($kinds)->toBe([AccountFailureKind::AuthFailed]);
});

it('treats transient-429 as transient on the result and ignores api_retry', function () {
    $classifier = new AccountFailureClassifier;

    foreach (runnerStreamLines('transient-429') as $line) {
        $kind = $classifier->classify($line)?->kind;

        if (($line['subtype'] ?? null) === 'api_retry') {
            expect($kind)->toBeNull();
        }

        if ($line['type'] === 'result') {
            expect($kind)->toBe(AccountFailureKind::TransientRateLimit);
        }
    }
});

it('finds no failure in benign fixtures', function (string $name) {
    $classifier = new AccountFailureClassifier;

    foreach (runnerStreamLines($name) as $line) {
        expect($classifier->classify($line))->toBeNull();
    }
})->with(['natural-turn-end', 'compaction', 'steer-in-grace-window']);

it('returns the utilisation of a rejected rate limit when present and null otherwise', function () {
    $base = ['type' => 'rate_limit_event', 'rate_limit_info' => ['status' => 'rejected', 'resetsAt' => 1794136119]];
    $withUtilisation = $base;
    $withUtilisation['rate_limit_info']['utilization'] = 0.97;

    expect(AccountFailureClassifier::rateLimitFrom($withUtilisation)['utilisation'])->toBe(0.97)
        ->and(AccountFailureClassifier::rateLimitFrom($base)['utilisation'])->toBeNull();
});
