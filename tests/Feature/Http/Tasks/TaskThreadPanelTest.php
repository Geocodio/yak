<?php

use App\Enums\ThreadBlockReason;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id, 'name' => 'My Max']);
    $this->thread = Thread::factory()->paused()->create([
        'claude_account_id' => $this->account->id,
        'model' => 'sonnet',
    ]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'started_by_user_id' => $this->user->id]);
});

it('shows the thread sandbox state, account and model', function () {
    $this->actingAs($this->user)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threadPanel.sandboxState', 'paused')
            ->where('threadPanel.account.name', 'My Max')
            ->where('threadPanel.account.isSystem', false)
            ->where('threadPanel.model', 'sonnet')
            ->where('threadPanel.blocked', null)
            ->where('threadPanel.guardStop', null)
            ->where('threadPanel.canContinue', false));
});

it('has no account or model switching', function () {
    expect(Route::has('tasks.thread.account.update'))->toBeFalse()
        ->and(Route::has('tasks.thread.model.update'))->toBeFalse()
        ->and(Schema::hasColumns('threads', ['account_switched_at', 'account_switched_by_user_id', 'runner_claude_account_id']))->toBeFalse();

    $this->actingAs($this->user)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page->missing('threadPanel.switchableAccounts')->missing('threadPanel.models'));
});

it('explains a blocked thread', function () {
    $this->thread->forceFill(['blocked_reason' => ThreadBlockReason::InvalidToken])->save();

    $this->actingAs($this->user)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threadPanel.blocked.reason', 'invalid_token')
            ->where('threadPanel.blocked.message', fn ($message) => str_contains($message, 'Settings')));
});

it('offers continue after a guard stop', function () {
    TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'turn_state' => TurnState::StoppedByGuard,
        'guard_reason' => 'assistant_turns',
    ]);

    $this->actingAs($this->user)->get(route('tasks.show', $this->task))
        ->assertInertia(fn (Assert $page) => $page
            ->where('threadPanel.guardStop.reason', 'assistant_turns')
            ->where('threadPanel.guardStop.limit', 300)
            ->where('threadPanel.canContinue', true));
});

it('omits the panel for tasks without a thread', function () {
    $plain = YakTask::factory()->create(['started_by_user_id' => $this->user->id]);

    $this->actingAs($this->user)->get(route('tasks.show', $plain))
        ->assertInertia(fn (Assert $page) => $page->where('threadPanel', null));
});

it('marks a turn with a second result as a follow-up', function () {
    $task = YakTask::factory()->create([
        'thread_id' => $this->thread->id,
        'started_by_user_id' => $this->user->id,
        'status' => 'success',
        'started_at' => now(),
        'result_summary' => 'Done.',
    ]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $this->thread->id, 'result_count' => 2]);

    $this->actingAs($this->user)->get(route('tasks.show', $task))
        ->assertInertia(fn (Assert $page) => $page->where('thread', fn ($entries) => collect($entries)->firstWhere('kind', 'yak')['followUpResult'] === true));
});
