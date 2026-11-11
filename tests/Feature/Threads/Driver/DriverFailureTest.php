<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\AccountFailure;
use App\Enums\ClaudeAccountStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\TurnState;
use App\Events\TurnFailed;
use App\Services\Threads\Contracts\BrokerStreams;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

it('blocks the thread when the usage limit is hit', function () {
    Event::fake([TurnFailed::class]);
    [$thread, $run] = runningTurnFor();

    replayRunnerFixture($thread, 'usage-limit');

    expect($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::UsageLimit)
        ->and($thread->blocked_at)->not->toBeNull()
        ->and($run->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and(collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->pluck('type')->all())->toContain('interrupt');
    Event::assertDispatchedTimes(TurnFailed::class, 1);
});

it('blocks the thread and marks the account when authentication fails', function () {
    Event::fake([TurnFailed::class]);
    bindTrackerChanges([new TurnTrackerChange(ended: true, failure: AccountFailure::AuthFailed)]);
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'assistant']);

    expect($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::AccountInvalid)
        ->and($thread->claudeAccount->status)->toBe(ClaudeAccountStatus::Invalid)
        ->and($run->refresh()->turn_state)->toBe(TurnState::Failed);
    Event::assertDispatchedTimes(TurnFailed::class, 1);
});

it('keeps the turn running through a transient rate limit', function () {
    Event::fake([TurnFailed::class]);
    bindTrackerChanges([new TurnTrackerChange(failure: AccountFailure::TransientRateLimit)]);
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'system', 'subtype' => 'api_retry']);

    expect($thread->refresh()->blocked_reason)->toBeNull()
        ->and($run->refresh()->turn_state)->toBe(TurnState::Running);
    Event::assertNotDispatched(TurnFailed::class);
});
