<?php

use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSessionKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\ThreadStreams;
use App\Services\Threads\ThreadTurns;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);

    $this->user = User::factory()->create();
    $this->current = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);
    $this->thread = Thread::factory()->paused()->create([
        'claude_account_id' => $this->current->id,
        'runner_claude_account_id' => $this->current->id,
        'runner_model' => 'opus',
        'blocked_reason' => ThreadBlockReason::UsageLimit,
    ]);
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id, 'started_by_user_id' => $this->user->id]);
});

it('lets a participant switch to their own account', function () {
    $mine = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->actingAs($this->user)->put(route('tasks.thread.account.update', $this->task), ['claude_account_id' => $mine->id])->assertRedirect();

    $thread = $this->thread->fresh();
    expect($thread->claude_account_id)->toBe($mine->id)
        ->and($thread->account_switched_by_user_id)->toBe($this->user->id)
        ->and($thread->account_switched_at)->not->toBeNull()
        ->and($thread->blocked_reason)->toBeNull();
});

it('lets a participant switch to a system account', function () {
    $system = ClaudeAccount::factory()->system()->create();

    $this->actingAs($this->user)->put(route('tasks.thread.account.update', $this->task), ['claude_account_id' => $system->id])->assertRedirect();

    expect($this->thread->fresh()->claude_account_id)->toBe($system->id);
});

it('forbids switching to another person\'s personal account', function () {
    $theirs = ClaudeAccount::factory()->create();

    $this->actingAs($this->user)->put(route('tasks.thread.account.update', $this->task), ['claude_account_id' => $theirs->id])->assertForbidden();

    expect($this->thread->fresh()->claude_account_id)->toBe($this->current->id);
});

it('forbids a person with no task in the thread', function () {
    $stranger = User::factory()->create();
    $mine = ClaudeAccount::factory()->create(['owner_user_id' => $stranger->id]);

    $this->actingAs($stranger)->put(route('tasks.thread.account.update', $this->task), ['claude_account_id' => $mine->id])->assertForbidden();
});

it('restarts the runner with resume after a switch', function () {
    $this->thread->sessions()->create(['session_id' => 'session-4', 'kind' => ThreadSessionKind::Main]);
    $this->thread->forceFill(['runner_state' => RunnerState::Idle])->save();
    $mine = ClaudeAccount::factory()->create(['owner_user_id' => $this->user->id]);

    $this->actingAs($this->user)->put(route('tasks.thread.account.update', $this->task), ['claude_account_id' => $mine->id]);
    app(ThreadTurns::class)->start($this->task, TaskRunKind::FollowUp, 'go', 'rules', $this->user->id, $this->thread->sandbox_container);

    $commands = $this->streams->commandsFor($this->thread->channel());
    expect(array_column($commands, 'type'))->toBe(['kill', 'start_runner', 'turn'])
        ->and($commands[1]['start']['resume'])->toBe('session-4')
        ->and(app(AuthProxyPlaceholders::class)->resolve($commands[1]['start']['placeholder'])['value'])->toBe($mine->secret)
        ->and($this->thread->fresh()->runner_claude_account_id)->toBe($mine->id);
});

it('changes the model without restarting the runner', function () {
    $this->thread->forceFill(['runner_state' => RunnerState::Idle, 'blocked_reason' => null])->save();

    $this->actingAs($this->user)->put(route('tasks.thread.model.update', $this->task), ['model' => 'sonnet'])->assertRedirect();
    app(ThreadTurns::class)->start($this->task, TaskRunKind::FollowUp, 'go', 'rules', $this->user->id, $this->thread->sandbox_container);

    expect($this->thread->fresh()->model)->toBe('sonnet')
        ->and(array_column($this->streams->commandsFor($this->thread->channel()), 'type'))->toBe(['set_model', 'turn']);
});
