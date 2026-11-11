<?php

use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Queue;

function rebuildingThread(User $user, ?ThreadBlockReason $blockedReason = null): Thread
{
    $thread = Thread::factory()->create([
        'created_by_user_id' => $user->id,
        'repo' => 'acme/site',
        'branch' => 'yak/fix-login',
        'sandbox_state' => ThreadSandboxState::Starting,
        'blocked_reason' => $blockedReason,
    ]);
    $steps = [RestoreStepName::CreateSandbox, RestoreStepName::CheckoutBranch, RestoreStepName::RunSetupScript, RestoreStepName::RunRefreshTriggers, RestoreStepName::RestoreSessionFiles, RestoreStepName::PrepareEnvironment];

    foreach ($steps as $index => $step) {
        $factory = ThreadRestoreStep::factory();
        $factory = match (true) {
            $index < 2 => $factory->state(['status' => RestoreStepStatus::Succeeded, 'started_at' => now()->subSeconds(10), 'finished_at' => now()->subSeconds(8)]),
            $index === 2 && $blockedReason === ThreadBlockReason::RestoreFailed => $factory->failed('Setup script failed with exit code 127')->state(['output' => "npm ci\nsh: 1: composer: not found\nline three\nline four\nline five\nline six"]),
            $index === 2 => $factory->state(['status' => RestoreStepStatus::Running, 'started_at' => now(), 'output' => "npm ci\nadded 120 packages"]),
            default => $factory,
        };
        $factory->create(['thread_id' => $thread->id, 'position' => $index + 1, 'step' => $step]);
    }

    return $thread;
}

it('shows waiting for account and waiting for a sandbox', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::WaitingForAccount]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertVisible('[data-testid="thread-notice"][data-kind="waiting_for_account"]')
        ->assertNoJavaScriptErrors();
});

it('shows a failed restore step with Retry and resumes the restore on click', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::RestoreFailed]);
    ThreadRestoreStep::factory()->failed('Branch is gone')->create(['thread_id' => $thread->id, 'position' => 1, 'step' => RestoreStepName::CheckoutBranch]);
    $this->actingAs($user);
    Queue::fake();

    $page = visit("/threads/{$thread->id}");
    $page->script("document.querySelector('[data-testid=\"restore-retry\"]').click()");
    $page->wait(8)->assertNoJavaScriptErrors();

    Queue::assertPushed(RetryThreadRestoreJob::class);
});

it('Continue starts a new turn after a guard stop', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::StoppedByGuard, 'started_at' => now()->subHour()]);
    $this->actingAs($user);
    $this->mock(ThreadTurns::class)->shouldReceive('start')->once()->andReturn(new TaskRun);

    $page = visit("/threads/{$thread->id}");
    $page->script("document.querySelector('[data-testid=\"thread-continue\"]').click()");
    $page->wait(8)->assertNoJavaScriptErrors();
});

it('shows the rebuild card with step 3 of 6 and detail lines', function () {
    $user = User::factory()->create();
    $thread = rebuildingThread($user);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertSeeIn('[data-testid="thread-notice"]', 'Rebuilding the sandbox')
        ->assertSeeIn('[data-testid="rebuild-step-count"]', 'Step 3 of 6')
        ->assertSeeIn('[data-testid="restore-progress"]', 'Run .yak/setup.sh')
        ->assertSeeIn('[data-testid="restore-progress"]', 'Installs what the repository needs to run.')
        ->assertSeeIn('[data-testid="restore-progress"]', 'added 120 packages')
        ->assertSeeIn('[data-testid="thread-status-pill"]', 'Rebuilding')
        ->assertNoJavaScriptErrors();
});

it('shows the failed step panel with Retry from this step, the script link and Start a new thread from this branch', function () {
    $user = User::factory()->create();
    $thread = rebuildingThread($user, ThreadBlockReason::RestoreFailed);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertSeeIn('[data-testid="thread-notice"]', 'Rebuilding the sandbox stopped at step 3')
        ->assertSeeIn('[data-testid="rebuild-failed-panel"]', '.yak/setup.sh stopped with exit code 127')
        ->assertSeeIn('[data-testid="rebuild-output-tail"]', 'composer: not found')
        ->assertSeeIn('[data-testid="restore-retry"]', 'Retry from this step')
        ->assertSeeIn('[data-testid="rebuild-failed-panel"]', 'Open the script on GitHub')
        ->assertSeeIn('[data-testid="rebuild-failed-panel"]', 'Start a new thread from this branch')
        ->assertSeeIn('[data-testid="thread-status-pill"]', 'Rebuild failed')
        ->assertNoJavaScriptErrors();
});

it('renders a failed step that has no output tail and no exit code', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::RestoreFailed]);
    ThreadRestoreStep::factory()->failed('Branch is gone')->create(['thread_id' => $thread->id, 'position' => 1, 'step' => RestoreStepName::CheckoutBranch]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertSeeIn('[data-testid="rebuild-failed-panel"]', 'Check out the thread branch stopped')
        ->assertSeeIn('[data-testid="rebuild-failed-panel"]', 'Branch is gone')
        ->assertVisible('[data-testid="restore-retry"]')
        ->assertNoJavaScriptErrors();
});

it('shows the sidebar status card while rebuilding and a second retry when it failed', function () {
    $user = User::factory()->create();
    $rebuilding = rebuildingThread($user);
    $failed = rebuildingThread($user, ThreadBlockReason::RestoreFailed);
    $this->actingAs($user);

    visit("/threads/{$rebuilding->id}")
        ->click('[data-testid="thread-panel-tab-environment"]')
        ->assertSeeIn('[data-testid="environment-status-card"]', 'Rebuilding, step 3 of 6')
        ->assertSeeIn('[data-testid="environment-status-card"]', 'Run .yak/setup.sh.')
        ->assertNoJavaScriptErrors();

    visit("/threads/{$failed->id}")
        ->click('[data-testid="thread-panel-tab-environment"]')
        ->assertSeeIn('[data-testid="environment-status-card"]', 'Rebuild stopped at step 3')
        ->assertSeeIn('[data-testid="sidebar-restore-retry"]', 'Retry from this step')
        ->assertNoJavaScriptErrors();
});

it('keeps a failed rebuild in view on a phone without horizontal scroll', function () {
    $user = User::factory()->create();
    $thread = rebuildingThread($user, ThreadBlockReason::RestoreFailed);
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}")->resize(390, 844);
    $page->assertSeeIn('[data-testid="failed-step-strip"]', 'Step 3 of 6 failed')
        ->assertVisible('[data-testid="strip-restore-retry"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});

it('shows the account waiting card with its turn meter and queue position', function () {
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['name' => 'Work', 'max_concurrent_turns' => 2]);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::WaitingForAccount]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'claude_account_id' => $account->id, 'turn_state' => TurnState::WaitingForAccount]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertSeeIn('[data-testid="thread-notice"]', 'Work is already running its 2 turns at once')
        ->assertSeeIn('[data-testid="thread-notice"]', 'Turns on this account')
        ->assertSeeIn('[data-testid="thread-notice"]', 'Only account admins can change this limit.')
        ->assertNoJavaScriptErrors();
});

it('shows the sandbox waiting card with a slot strip and no time estimate', function () {
    config(['yak.threads.awake_cap' => 2, 'yak.threads.person_awake_cap' => 5]);
    $user = User::factory()->create();
    Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'c-1']);
    Thread::factory()->paused()->create(['last_activity_at' => now()->subMinutes(3)]);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()]);
    $this->actingAs($user);

    $page = visit("/threads/{$thread->id}");
    $page->assertSeeIn('[data-testid="thread-notice"]', 'Yak stops a paused one after 5 idle minutes. It starts this one soon. Your message is saved.')
        ->assertDontSee('usually within a few minutes')
        ->assertCount('[data-testid="waiting-slots"] li', 2)
        ->assertNoJavaScriptErrors();
});

it('shows the share waiting card with the viewer own sandboxes', function () {
    config(['yak.threads.awake_cap' => 8, 'yak.threads.person_awake_cap' => 1]);
    $user = User::factory()->create();
    Thread::factory()->paused()->create(['owner_user_id' => $user->id, 'last_activity_at' => now()->subMinutes(2)]);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertSeeIn('[data-testid="thread-notice"]', 'Waiting for your share of sandboxes')
        ->assertSeeIn('[data-testid="waiting-own-threads"]', 'Paused, idle 2m')
        ->assertNoJavaScriptErrors();
});

it('shows the sidebar capacity block while waiting and nothing without capacity values', function () {
    config(['yak.threads.awake_cap' => 2, 'yak.threads.person_awake_cap' => 3]);
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['name' => 'Work', 'max_concurrent_turns' => 2]);
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()]);
    $bare = Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'blocked_at' => now()]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->click('[data-testid="thread-panel-tab-environment"]')
        ->assertSeeIn('[data-testid="capacity-block"]', 'Turns on Work')
        ->assertSeeIn('[data-testid="capacity-block"]', 'Awake sandboxes')
        ->assertSeeIn('[data-testid="capacity-block"]', 'Your share')
        ->assertNoJavaScriptErrors();
    visit("/threads/{$bare->id}")->click('[data-testid="thread-panel-tab-environment"]')->wait(1)->assertMissing('[data-testid="capacity-block"]')->assertNoJavaScriptErrors();
});

it('shows Replace token only to the account owner on a token problem', function () {
    $owner = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $owner->id]);
    $thread = Thread::factory()->create(['created_by_user_id' => $owner->id, 'claude_account_id' => $account->id, 'blocked_reason' => ThreadBlockReason::AccountInvalid]);
    $this->actingAs($owner);

    visit("/threads/{$thread->id}")
        ->assertVisible('[data-testid="thread-notice"][data-kind="account_invalid"]')
        ->assertSee('Replace token')
        ->assertSee('Start a new thread on your own account')
        ->assertNoJavaScriptErrors();
});

it('shows the reset time and a countdown on a usage limit', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::UsageLimit]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'tracker_state' => json_encode(['rate_limit_resets_at' => now()->addHour()->toIso8601String()])]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertVisible('[data-testid="thread-notice"][data-kind="usage_limit"]')
        ->assertSee('Resets at')
        ->assertVisible('[data-testid="usage-countdown"]')
        ->assertNoJavaScriptErrors();
});
