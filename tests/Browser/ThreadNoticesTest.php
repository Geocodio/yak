<?php

use App\Enums\RestoreStepName;
use App\Enums\ThreadBlockReason;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use App\Models\User;
use App\Models\YakTask;

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

    visit("/threads/{$thread->id}")
        ->assertVisible('[data-testid="restore-retry"]')
        ->click('[data-testid="restore-retry"]')
        ->assertNoJavaScriptErrors();
});

it('Continue starts a new turn after a guard stop', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::StoppedByGuard, 'started_at' => now()->subHour()]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertVisible('[data-testid="thread-continue"]')
        ->click('[data-testid="thread-continue"]')
        ->assertNoJavaScriptErrors();
});
