<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\ClaudeAccountStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\TurnState;
use App\Events\TurnFailed;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\AccountFailure;
use App\Services\ClaudeAccounts\AccountFailureKind;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

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
    bindTrackerChanges([new TurnTrackerChange(ended: true, failure: new AccountFailure(AccountFailureKind::AuthFailed))]);
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'assistant']);

    expect($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::AccountInvalid)
        ->and($thread->claudeAccount->status)->toBe(ClaudeAccountStatus::Invalid)
        ->and($run->refresh()->turn_state)->toBe(TurnState::Failed);
    Event::assertDispatchedTimes(TurnFailed::class, 1);
});

it('keeps the turn running through a transient rate limit', function () {
    Event::fake([TurnFailed::class]);
    bindTrackerChanges([new TurnTrackerChange(failure: new AccountFailure(AccountFailureKind::TransientRateLimit))]);
    [$thread, $run] = runningTurnFor();

    pushRunnerLine($thread, 1, ['type' => 'system', 'subtype' => 'api_retry']);

    expect($thread->refresh()->blocked_reason)->toBeNull()
        ->and($run->refresh()->turn_state)->toBe(TurnState::Running);
    Event::assertNotDispatched(TurnFailed::class);
});

it('clears a usage-limit block after the reset and starts the pending turn on the tick', function () {
    $account = ClaudeAccount::factory()->usageLimited(now()->addHour()->toImmutable())->create();
    $thread = Thread::factory()->create(['claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id, 'thread_id' => $thread->id, 'claude_account_id' => $account->id,
        'turn_state' => TurnState::Pending, 'prompt_uuid' => fake()->uuid(), 'prompt' => 'go',
        'started_at' => now(), 'finished_at' => null,
    ]);
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn')->once();

    app(ThreadDriver::class)->tick();
    expect($run->refresh()->turn_state)->toBe(TurnState::Pending);

    $this->travel(61)->minutes();
    app(ThreadDriver::class)->tick();

    expect($thread->refresh()->blocked_reason)->toBeNull()
        ->and($run->refresh()->turn_state)->toBe(TurnState::Running);
});
