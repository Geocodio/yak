<?php

use App\Enums\ClaudeAccountStatus;
use App\Enums\ThreadBlockReason;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\ThreadBlockClearer;

function blockedThread(ClaudeAccount $account, ThreadBlockReason $reason): Thread
{
    return Thread::factory()->create(['claude_account_id' => $account->id, 'blocked_reason' => $reason, 'blocked_at' => now()]);
}

it('keeps a usage-limit block until the reset time passes, then clears it and the account column', function () {
    $account = ClaudeAccount::factory()->usageLimited(now()->addHour()->toImmutable())->create();
    $thread = blockedThread($account, ThreadBlockReason::UsageLimit);

    expect(app(ThreadBlockClearer::class)->clearResolved())->toBe(0)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::UsageLimit);

    $this->travel(61)->minutes();

    expect(app(ThreadBlockClearer::class)->clearResolved())->toBe(1)
        ->and($thread->refresh()->blocked_reason)->toBeNull()
        ->and($thread->blocked_at)->toBeNull()
        ->and($account->refresh()->usage_limit_resets_at)->toBeNull();
});

it('clears an invalid-account block only once the account is active again', function () {
    $account = ClaudeAccount::factory()->invalid()->create();
    $thread = blockedThread($account, ThreadBlockReason::AccountInvalid);

    expect(app(ThreadBlockClearer::class)->clearResolved())->toBe(0)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::AccountInvalid);

    $account->forceFill(['status' => ClaudeAccountStatus::Active])->save();

    expect(app(ThreadBlockClearer::class)->clearResolved())->toBe(1)
        ->and($thread->refresh()->blocked_reason)->toBeNull();
});

it('never touches a thread waiting for a sandbox', function () {
    $account = ClaudeAccount::factory()->create();
    $thread = blockedThread($account, ThreadBlockReason::WaitingForSandbox);

    expect(app(ThreadBlockClearer::class)->clearResolved())->toBe(0)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForSandbox);
});

it('clears every blocked thread of a rotated account', function () {
    $account = ClaudeAccount::factory()->create();
    $first = blockedThread($account, ThreadBlockReason::AccountInvalid);
    $second = blockedThread($account, ThreadBlockReason::AccountExpired);

    expect(app(ThreadBlockClearer::class)->clearForAccount($account))->toBe(2)
        ->and($first->refresh()->blocked_reason)->toBeNull()
        ->and($second->refresh()->blocked_reason)->toBeNull();
});

it('leaves a usage-limit block alone when an account is rotated', function () {
    $account = ClaudeAccount::factory()->usageLimited()->create();
    $thread = blockedThread($account, ThreadBlockReason::UsageLimit);

    expect(app(ThreadBlockClearer::class)->clearForAccount($account))->toBe(0)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::UsageLimit);
});
