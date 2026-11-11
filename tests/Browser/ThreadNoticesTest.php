<?php

use App\Enums\RestoreStepName;
use App\Enums\ThreadBlockReason;
use App\Enums\TurnState;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Queue;

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

it('shows the usage limit notice with a new thread button and the composer placeholder', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id, 'blocked_reason' => ThreadBlockReason::UsageLimit]);
    $this->actingAs($user);

    visit("/threads/{$thread->id}")
        ->assertSeeIn('[data-testid="thread-notice"][data-kind="usage_limit"]', 'Usage limit reached')
        ->assertSeeIn('[data-testid="usage-limit-new-thread"]', 'Start a new thread on another account')
        ->assertAttributeContains('[data-testid="usage-limit-new-thread"]', 'href', 'repo=')
        ->assertAttribute('[data-testid="thread-composer"] textarea', 'placeholder', 'Message Claude. It reads this after the limit resets.')
        ->assertDontSee('null')
        ->assertNoJavaScriptErrors();
});
