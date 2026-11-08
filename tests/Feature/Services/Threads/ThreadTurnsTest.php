<?php

use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadStreams;
use App\Services\Threads\ThreadTurns;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    $this->account = ClaudeAccount::factory()->create();
    $this->thread = Thread::factory()->paused()->create(['claude_account_id' => $this->account->id, 'runner_state' => RunnerState::None, 'model' => 'opus']);
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
});

function startTurn(Thread $thread, YakTask $task, ?int $driverUserId = null)
{
    return app(ThreadTurns::class)->start($task, TaskRunKind::Initial, 'Fix the bug', 'system rules', $driverUserId, $thread->sandbox_container);
}

function sentTypes(Thread $thread): array
{
    return array_column(test()->streams->commandsFor($thread->channel()), 'type');
}

it('starts the runner before the first turn', function () {
    $run = startTurn($this->thread, $this->task);

    expect(sentTypes($this->thread))->toBe(['start_runner', 'turn'])
        ->and($this->streams->commandsFor($this->thread->channel())[1]['uuid'])->toBe($run->prompt_uuid)
        ->and($run->turn_state)->toBe(TurnState::Running);
});

it('resumes the active session when starting a runner', function () {
    $this->thread->sessions()->create(['session_id' => 'session-9', 'kind' => ThreadSessionKind::Main]);

    startTurn($this->thread, $this->task);

    expect($this->streams->commandsFor($this->thread->channel())[0]['start']['resume'])->toBe('session-9');
});

it('only sets the model on a live runner', function () {
    $first = startTurn($this->thread, $this->task);
    $first->forceFill(['turn_state' => TurnState::Completed])->save();
    Thread::query()->whereKey($this->thread->id)->update(['runner_state' => RunnerState::Idle->value, 'model' => 'sonnet', 'lock_holder' => ThreadLockHolder::None->value, 'lock_turn_id' => null]);

    startTurn($this->thread->fresh(), $this->task);

    expect(sentTypes($this->thread))->toBe(['start_runner', 'turn', 'set_model', 'turn'])
        ->and($this->streams->commandsFor($this->thread->channel())[0]['start']['model'])->toBe('opus');
});

it('takes and records the thread lock and owner', function () {
    $user = User::factory()->create();

    $run = startTurn($this->thread, $this->task, $user->id);

    $thread = $this->thread->fresh();
    expect($thread->lock_holder)->toBe(ThreadLockHolder::Sandbox)
        ->and($thread->lock_turn_id)->toBe($run->id)
        ->and($thread->owner_user_id)->toBe($user->id)
        ->and($run->driver_user_id)->toBe($user->id)
        ->and($run->claude_account_id)->toBe($this->account->id);
});
