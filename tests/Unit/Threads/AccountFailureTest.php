<?php

use App\Enums\ThreadBlockReason;
use App\Services\ClaudeAccounts\AccountFailure;
use App\Services\ClaudeAccounts\AccountFailureKind;
use Carbon\CarbonImmutable;

it('maps kinds to block reasons', function () {
    expect(AccountFailureKind::AuthFailed->blockReason())->toBe(ThreadBlockReason::AccountInvalid)
        ->and(AccountFailureKind::UsageLimit->blockReason())->toBe(ThreadBlockReason::UsageLimit)
        ->and(AccountFailureKind::TransientRateLimit->blockReason())->toBeNull();
});

it('blocks only for auth and usage limit failures', function () {
    expect((new AccountFailure(AccountFailureKind::AuthFailed))->isBlocking())->toBeTrue()
        ->and((new AccountFailure(AccountFailureKind::UsageLimit))->isBlocking())->toBeTrue()
        ->and((new AccountFailure(AccountFailureKind::TransientRateLimit))->isBlocking())->toBeFalse();
});

it('returns a new instance from withRateLimit', function () {
    $original = new AccountFailure(AccountFailureKind::UsageLimit);
    $resetsAt = CarbonImmutable::parse('2026-11-11 12:00:00');

    $updated = $original->withRateLimit($resetsAt, 'five_hour');

    expect($updated)->not->toBe($original)
        ->and($original->resetsAt)->toBeNull()
        ->and($updated->kind)->toBe(AccountFailureKind::UsageLimit)
        ->and($updated->resetsAt)->toBe($resetsAt)
        ->and($updated->rateLimitType)->toBe('five_hour');
});
