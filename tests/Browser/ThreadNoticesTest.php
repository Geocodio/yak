<?php

use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\RetryThreadRestoreJob;
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
