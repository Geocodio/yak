<?php

use App\Enums\RestoreStepName;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Http\Resources\ThreadNoticeData;
use App\Models\ClaudeAccount;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\RunawayGuard;
use Carbon\CarbonImmutable;

function stoppedByGuard(?string $reason): Thread
{
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::StoppedByGuard]);

    if ($reason !== null) {
        TaskLog::factory()->create(['yak_task_id' => $task->id, 'message' => 'turn_stopped_by_guard', 'level' => 'warning', 'metadata' => ['reason' => $reason]]);
    }

    return $thread;
}

it('has one notice per block reason', function (ThreadBlockReason $reason) {
    $thread = Thread::factory()->create(['blocked_reason' => $reason]);

    expect(ThreadNoticeData::for($thread))->toMatchArray(['kind' => $reason->value, 'canContinue' => false]);
})->with(ThreadBlockReason::cases());

it('is null when nothing blocks', function () {
    expect(ThreadNoticeData::for(Thread::factory()->create()))->toBeNull();
});

it('reads a turns guard stop from the log row', function () {
    config(['yak.max_turns' => 150]);

    expect(ThreadNoticeData::for(stoppedByGuard(RunawayGuard::ASSISTANT_TURNS)))
        ->toMatchArray(['kind' => 'guard_turns', 'title' => 'Claude stopped after 150 steps', 'canContinue' => true]);
});

it('reads a wall clock guard stop from the log row', function () {
    config(['yak.threads.turn_wall_clock_minutes' => 120]);

    expect(ThreadNoticeData::for(stoppedByGuard(RunawayGuard::WALL_CLOCK)))
        ->toMatchArray(['kind' => 'guard_time', 'title' => 'Claude stopped after 120 minutes', 'canContinue' => true]);
});

it('falls back to a turns guard stop without a log row', function () {
    expect(ThreadNoticeData::for(stoppedByGuard(null))['kind'])->toBe('guard_turns');
});

it('ignores a guard log row from an earlier turn', function () {
    $thread = stoppedByGuard(null);
    $task = $thread->tasks()->firstOrFail();
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'message' => 'turn_stopped_by_guard', 'metadata' => ['reason' => RunawayGuard::WALL_CLOCK], 'created_at' => now()->subDay()]);

    expect(ThreadNoticeData::for($thread)['kind'])->toBe('guard_turns');
});

it('does not offer Continue when the last turn ended normally', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);

    expect(ThreadNoticeData::for($thread))->toBeNull();
});

it('words an invalid account differently for the owner and a teammate', function () {
    $owner = User::factory()->create(['name' => 'Anna Larsen']);
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Work']);
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::AccountInvalid, 'claude_account_id' => $account->id]);

    $forOwner = ThreadNoticeData::for($thread, $owner);
    $forTeammate = ThreadNoticeData::for($thread, User::factory()->create());

    expect($forOwner)->toMatchArray(['isOwnerOfAccount' => true, 'title' => 'Your token stopped working'])
        ->and($forTeammate)->toMatchArray(['isOwnerOfAccount' => false, 'title' => "Anna's token stopped working"])
        ->and($forTeammate['body'])->toContain('Only Anna can replace it');
});

it('lists restore steps with the failed one', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'position' => 1, 'step' => RestoreStepName::CreateSandbox, 'status' => 'succeeded']);
    ThreadRestoreStep::factory()->failed('Branch is gone')->create(['thread_id' => $thread->id, 'position' => 2, 'step' => RestoreStepName::CheckoutBranch]);

    $notice = ThreadNoticeData::for($thread);

    expect($notice['restoreSteps'][0])->toMatchArray(['key' => 'create_sandbox', 'status' => 'done', 'error' => null])
        ->and($notice['restoreSteps'][1])->toMatchArray(['key' => 'checkout_branch', 'status' => 'failed', 'error' => 'Branch is gone', 'outputTail' => [], 'exitCode' => null]);
});

it('labels restore steps with the mockup wording and detail lines', function () {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Starting]);

    foreach ([RestoreStepName::CreateSandbox, RestoreStepName::CheckoutBranch, RestoreStepName::RunSetupScript, RestoreStepName::RunRefreshTriggers, RestoreStepName::RestoreSessionFiles, RestoreStepName::PrepareEnvironment] as $index => $name) {
        ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'position' => $index + 1, 'step' => $name, 'status' => 'pending']);
    }

    $steps = ThreadNoticeData::for($thread)['restoreSteps'];

    expect(array_column($steps, 'label'))->toBe(['Clone the repo template', 'Check out the thread branch', 'Run .yak/setup.sh', 'Run the refresh triggers', 'Restore the conversation', 'Start Claude'])
        ->and(collect($steps)->every(fn (array $step): bool => $step['detail'] !== ''))->toBeTrue();
});

it('reports durationSeconds from started_at and finished_at', function () {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Starting]);
    ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'position' => 1, 'step' => RestoreStepName::CreateSandbox, 'status' => 'succeeded', 'started_at' => now()->subSeconds(12), 'finished_at' => now()]);
    ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'position' => 2, 'step' => RestoreStepName::CheckoutBranch, 'status' => 'running']);

    $steps = ThreadNoticeData::for($thread)['restoreSteps'];

    expect($steps[0]['durationSeconds'])->toBe(12.0)
        ->and($steps[1]['durationSeconds'])->toBeNull();
});

it('keeps the last 5 output lines as outputTail and parses "exit code N" from the error', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    ThreadRestoreStep::factory()->failed('setup.sh failed with exit code 127')->create([
        'thread_id' => $thread->id, 'position' => 1, 'step' => RestoreStepName::RunSetupScript,
        'output' => implode("\n", ['one', 'two', 'three', 'four', 'five', 'six', 'seven']),
    ]);

    $step = ThreadNoticeData::for($thread)['restoreSteps'][0];

    expect($step['outputTail'])->toBe(['three', 'four', 'five', 'six', 'seven'])
        ->and($step['exitCode'])->toBe(127);
});

it('titles a rebuild failure with the failed step number', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'position' => 1, 'step' => RestoreStepName::CreateSandbox, 'status' => 'succeeded']);
    ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'position' => 2, 'step' => RestoreStepName::CheckoutBranch, 'status' => 'succeeded']);
    ThreadRestoreStep::factory()->failed('boom')->create(['thread_id' => $thread->id, 'position' => 3, 'step' => RestoreStepName::RunSetupScript]);

    expect(ThreadNoticeData::for($thread))->toMatchArray([
        'title' => 'Rebuilding the sandbox stopped at step 3',
        'body' => 'Your message is saved. Claude reads it once the sandbox is back.',
    ]);
});

it('shows restore progress while the sandbox starts', function () {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Starting]);
    ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'status' => 'running']);

    expect(ThreadNoticeData::for($thread))->toMatchArray(['kind' => 'restoring', 'title' => 'Rebuilding the sandbox', 'canContinue' => false])
        ->and(ThreadNoticeData::for($thread)['restoreSteps'])->toHaveCount(1);
});

it('reads the usage limit reset time from the run', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::UsageLimit]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'tracker_state' => json_encode(['failure' => ['resetsAt' => '2026-11-11T16:30:00+00:00']])]);

    expect(ThreadNoticeData::for($thread)['resetsAt'])->toBe('2026-11-11T16:30:00+00:00');
});

it('prefers the account reset time over the tracker state', function () {
    $account = ClaudeAccount::factory()->usageLimited(CarbonImmutable::parse('2026-11-11T18:00:00+00:00'))->create();
    $thread = Thread::factory()->create(['claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::UsageLimit]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'tracker_state' => json_encode(['failure' => ['resetsAt' => '2026-11-11T16:30:00+00:00']])]);

    expect(ThreadNoticeData::for($thread)['resetsAt'])->toBe('2026-11-11T18:00:00+00:00');
});

it('names the queue position of a thread waiting for an account', function () {
    $account = ClaudeAccount::factory()->create(['name' => 'Team']);
    $waiting = fn (): Thread => tap(Thread::factory()->create(['claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::WaitingForAccount]), function (Thread $thread) use ($account): void {
        TaskRun::factory()->create(['thread_id' => $thread->id, 'claude_account_id' => $account->id, 'turn_state' => TurnState::WaitingForAccount]);
    });
    $first = $waiting();
    $second = $waiting();

    expect(ThreadNoticeData::for($first)['body'])->toContain('Team is running its maximum number of turns. You are number 1 in line.')
        ->and(ThreadNoticeData::for($second)['body'])->toContain('You are number 2 in line.');

    TaskRun::query()->where('thread_id', $first->id)->update(['turn_state' => TurnState::Pending]);

    expect(ThreadNoticeData::for($second->fresh())['body'])->toContain('You are number 1 in line.');
});

it('counts only healthy waiters ahead and drops the sentence for an unhealthy own thread', function () {
    $account = ClaudeAccount::factory()->create(['name' => 'Team']);
    $waiting = fn (array $attributes = []): Thread => tap(Thread::factory()->create(['claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::WaitingForAccount, ...$attributes]), function (Thread $thread) use ($account): void {
        TaskRun::factory()->create(['thread_id' => $thread->id, 'claude_account_id' => $account->id, 'turn_state' => TurnState::WaitingForAccount]);
    });
    $closed = $waiting(['status' => ThreadStatus::Closed]);
    $mine = $waiting();

    expect(ThreadNoticeData::for($mine)['body'])->toContain('You are number 1 in line.');

    $healthy = $waiting();
    $later = $waiting();

    expect(ThreadNoticeData::for($later)['body'])->toContain('You are number 3 in line.');

    $mine->forceFill(['status' => ThreadStatus::Closed])->save();
    $healthy->forceFill(['blocked_reason' => ThreadBlockReason::UsageLimit])->save();

    expect(ThreadNoticeData::for($later->fresh())['body'])->toContain('You are number 1 in line.')
        ->and(ThreadNoticeData::for($healthy->fresh())['body'] ?? '')->not->toContain('in line.');
});

it('reports the queue position and turn counts for a thread waiting for its account', function () {
    $account = ClaudeAccount::factory()->create(['name' => 'Work', 'max_concurrent_turns' => 2]);
    TaskRun::factory()->count(2)->create(['claude_account_id' => $account->id, 'account_slot_held_at' => now()]);

    $first = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::WaitingForAccount, 'claude_account_id' => $account->id]);
    $second = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::WaitingForAccount, 'claude_account_id' => $account->id]);
    foreach ([$first, $second] as $thread) {
        $task = YakTask::factory()->create(['thread_id' => $thread->id]);
        TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'claude_account_id' => $account->id, 'turn_state' => TurnState::WaitingForAccount]);
    }

    expect(ThreadNoticeData::for($first)['waiting'])->toMatchArray(['position' => 1, 'turnsRunning' => 2, 'turnsLimit' => 2, 'accountName' => 'Work', 'slots' => []])
        ->and(ThreadNoticeData::for($second)['waiting']['position'])->toBe(2);
});

it('lists the awake sandboxes for a thread waiting for a sandbox', function () {
    config(['yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 4]);
    $viewer = User::factory()->create();
    Thread::factory()->count(6)->create(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => null])->each(fn (Thread $thread) => $thread->update(['sandbox_container' => 'c-' . $thread->id]));
    Thread::factory()->paused()->create(['owner_user_id' => $viewer->id, 'last_activity_at' => now()->subMinutes(40)]);
    Thread::factory()->paused()->create(['last_activity_at' => now()->subMinutes(5)]);
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now(), 'owner_user_id' => $viewer->id]);

    $waiting = ThreadNoticeData::for($thread, $viewer)['waiting'];

    expect($waiting['slots'])->toHaveCount(8)
        ->and(collect($waiting['slots'])->where('state', 'paused')->pluck('idleMinutes')->sort()->values()->all())->toBe([5, 40])
        ->and(collect($waiting['slots'])->where('isMine', true))->toHaveCount(1)
        ->and($waiting['position'])->toBe(1)
        ->and($waiting['ownSlotsCap'])->toBeNull();
});

it('offers the viewer a paused sandbox to stop when their own share is full', function () {
    config(['yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 1]);
    $viewer = User::factory()->create();
    $paused = Thread::factory()->paused()->create(['owner_user_id' => $viewer->id, 'last_activity_at' => now()->subHour()]);
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now(), 'owner_user_id' => $viewer->id]);

    expect(ThreadNoticeData::for($thread, $viewer)['waiting'])->toMatchArray(['ownSlotsUsed' => 1, 'ownSlotsCap' => 1, 'stoppableThreadId' => $paused->id])
        ->and(ThreadNoticeData::for($thread, User::factory()->create())['waiting']['stoppableThreadId'])->toBeNull();
});

it('has no waiting props for other notices', function () {
    expect(ThreadNoticeData::for(Thread::factory()->create(['blocked_reason' => ThreadBlockReason::UsageLimit]))['waiting'])->toBeNull();
});

it('gives no time estimate for a thread waiting for a sandbox', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()]);

    expect(ThreadNoticeData::for($thread)['body'])->not->toContain('usually within a few minutes');
});

it('links the account settings for the owner and a new thread for everyone on a token problem', function () {
    $owner = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $owner->id]);
    $thread = Thread::factory()->create(['claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::AccountInvalid]);

    expect(ThreadNoticeData::for($thread, $owner))
        ->toMatchArray(['accountSettingsUrl' => route('settings.claude-accounts'), 'startOnOwnAccountUrl' => route('threads.new')])
        ->and(ThreadNoticeData::for($thread, User::factory()->create()))
        ->toMatchArray(['accountSettingsUrl' => null, 'startOnOwnAccountUrl' => route('threads.new')]);
});

it('has no account links on other notices', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::UsageLimit]);

    expect(ThreadNoticeData::for($thread))->toMatchArray(['accountSettingsUrl' => null, 'startOnOwnAccountUrl' => null]);
});
