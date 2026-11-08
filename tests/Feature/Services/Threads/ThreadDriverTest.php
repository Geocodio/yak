<?php

use App\DataTransferObjects\StreamEntry;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Models\ClaudeAccount;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use App\Services\Threads\TurnResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    Event::fake([TurnStarted::class, TurnCompleted::class, TurnFailed::class]);

    $this->thread = Thread::factory()->paused()->create(['lock_holder' => ThreadLockHolder::Sandbox]);
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
    $this->run = TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'turn_state' => TurnState::Running,
        'prompt_uuid' => 'prompt-1',
        'command_states' => ['prompt-1' => 'sent'],
        'session_id' => 'session-1',
        'cost_usd' => 0,
    ]);
    $this->thread->forceFill(['lock_turn_id' => $this->run->id])->save();
    $this->sequence = 0;
});

function threadEntry(Thread $thread, array $line, ?int $sequence = null, int $generation = 1, bool $fromBroker = false): StreamEntry
{
    static $counter = 0;
    $sequence ??= ++$counter;

    return new StreamEntry($thread->channel(), $sequence . '-0', $generation, $sequence, $line, $fromBroker || ($line['type'] ?? null) === 'broker');
}

/**
 * @return list<array<string, mixed>>
 */
function threadTurnLines(float $cost = 0.5, bool $isError = false): array
{
    return [
        ['type' => 'command_lifecycle', 'command_uuid' => 'prompt-1', 'state' => 'started', 'session_id' => 'session-1'],
        ['type' => 'assistant', 'message' => ['id' => 'm1', 'model' => 'opus', 'content' => [['type' => 'text', 'text' => 'Working on it']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]],
        ['type' => 'command_lifecycle', 'command_uuid' => 'prompt-1', 'state' => 'completed', 'session_id' => 'session-1'],
        ['type' => 'result', 'subtype' => $isError ? 'error_during_execution' : 'success', 'is_error' => $isError, 'result' => 'All done', 'session_id' => 'session-1', 'total_cost_usd' => $cost, 'num_turns' => 1, 'duration_ms' => 1200],
    ];
}

function driveThread(Thread $thread, array $lines): void
{
    foreach ($lines as $line) {
        app(ThreadDriver::class)->handle(threadEntry($thread, $line));
    }
}

it('writes runner output into task logs', function () {
    driveThread($this->thread, threadTurnLines());

    expect(TaskLog::query()->where('yak_task_id', $this->task->id)->where('message', 'Working on it')->count())->toBe(1);
    Event::assertDispatched(TurnStarted::class, 1);
});

it('completes a turn once and fires TurnCompleted once', function () {
    driveThread($this->thread, threadTurnLines());

    $run = $this->run->fresh();
    expect($run->turn_state)->toBe(TurnState::Completed)
        ->and((float) $run->cost_usd)->toBe(0.5)
        ->and($run->assistant_messages)->toBe(1)
        ->and($run->completion_dispatched_at)->not->toBeNull();
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    Event::assertNotDispatched(TurnFailed::class);
});

it('pauses the sandbox once the turn ends', function () {
    Process::fake(['*' => Process::result()]);
    $this->thread->forceFill(['sandbox_state' => ThreadSandboxState::Running])->save();

    driveThread($this->thread, threadTurnLines());

    expect($this->thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
    Process::assertRan("incus pause '{$this->thread->sandbox_container}'");
});

it('fails a turn whose last result is an error', function () {
    driveThread($this->thread, threadTurnLines(0.2, true));

    expect($this->run->fresh()->turn_state)->toBe(TurnState::Failed);
    Event::assertDispatchedTimes(TurnFailed::class, 1);
    Event::assertNotDispatched(TurnCompleted::class);
});

it('handles a replayed entry once', function () {
    $entries = array_map(fn (array $line): StreamEntry => threadEntry($this->thread, $line), threadTurnLines());

    foreach ($entries as $entry) {
        app(ThreadDriver::class)->handle($entry);
    }
    foreach ($entries as $entry) {
        app(ThreadDriver::class)->handle($entry);
    }

    expect(TaskLog::query()->where('yak_task_id', $this->task->id)->where('message', 'Working on it')->count())->toBe(1)
        ->and((float) $this->run->fresh()->cost_usd)->toBe(0.5)
        ->and($this->run->fresh()->assistant_messages)->toBe(1);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    expect($this->thread->fresh()->out_cursor)->toBe(end($entries)->id);
});

it('redispatches a completion lost before dispatch', function () {
    $this->run->forceFill(['turn_state' => TurnState::Completed, 'completion_dispatched_at' => null])->save();

    app(ThreadDriver::class)->tick();
    app(ThreadDriver::class)->tick();

    Event::assertDispatchedTimes(TurnCompleted::class, 1);
    expect($this->run->fresh()->completion_dispatched_at)->not->toBeNull();
});

it('records a new session from a clear as a clear session', function () {
    driveThread($this->thread, [
        ['type' => 'system', 'subtype' => 'init', 'session_id' => 'session-1'],
        ['type' => 'system', 'subtype' => 'init', 'session_id' => 'session-2'],
    ]);

    $sessions = $this->thread->sessions()->orderBy('id')->get();
    expect($sessions->pluck('session_id')->all())->toBe(['session-1', 'session-2'])
        ->and($sessions[0]->kind)->toBe(ThreadSessionKind::Main)
        ->and($sessions[1]->kind)->toBe(ThreadSessionKind::Clear)
        ->and($sessions[1]->parent_session_id)->toBe('session-1')
        ->and($this->task->fresh()->session_id)->toBe('session-2');
});

it('releases the thread lock when the turn ends', function () {
    driveThread($this->thread, threadTurnLines());

    $thread = $this->thread->fresh();
    expect($thread->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($thread->lock_turn_id)->toBeNull();
});

it('TurnResult rebuilds the agent result from the last result event', function () {
    driveThread($this->thread, threadTurnLines(0.5));
    $this->run->forceFill(['agent_ms' => 4000])->save();

    $result = TurnResult::toAgentRunResult($this->run->fresh());

    expect($result->sessionId)->toBe('session-1')
        ->and($result->resultSummary)->toBe('All done')
        ->and($result->costUsd)->toBe(0.5)
        ->and($result->numTurns)->toBe(1)
        ->and($result->durationMs)->toBe(4000)
        ->and($result->isError)->toBeFalse();
});

it('recovers a running turn when the runner exits', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id])->save();

    driveThread($this->thread, [['type' => 'broker', 'event' => 'runner_exited', 'generation' => 0, 'exit_code' => 1]]);

    expect(array_column($streams->commandsFor($this->thread->channel()), 'type'))->toBe(['kill', 'start_runner', 'turn'])
        ->and($this->run->fresh()->restarted)->toBeTrue();
});

it('only forgets the runner when the exit happens between turns', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->run->forceFill(['turn_state' => TurnState::Completed])->save();

    driveThread($this->thread, [['type' => 'broker', 'event' => 'runner_exited', 'generation' => 0, 'exit_code' => 0]]);

    expect($streams->commandsFor($this->thread->channel()))->toBe([])
        ->and($this->thread->fresh()->runner_state)->toBe(RunnerState::None);
});

it('ignores a broker event that the runner wrote itself', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id])->save();

    // Built by hand: threadEntry() marks type=broker lines as the broker's own.
    app(ThreadDriver::class)->handle(new StreamEntry($this->thread->channel(), '900-0', 1, 900, ['type' => 'broker', 'event' => 'runner_exited', 'exit_code' => 0]));

    expect($streams->commandsFor($this->thread->channel()))->toBe([])
        ->and($this->run->fresh()->turn_state)->toBe(TurnState::Running);
});

it('uses the generation the broker stamped, never one inside the line', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id, 'runner_generation' => 2, 'runner_state' => RunnerState::Busy])->save();

    app(ThreadDriver::class)->handle(threadEntry($this->thread, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 5], generation: 1));

    expect($streams->commandsFor($this->thread->channel()))->toBe([])
        ->and($this->thread->fresh()->runner_state)->toBe(RunnerState::Busy);
});

it('ignores agent output from an older runner generation', function () {
    $this->thread->forceFill(['runner_generation' => 2])->save();

    foreach (threadTurnLines() as $line) {
        app(ThreadDriver::class)->handle(threadEntry($this->thread, $line, generation: 1));
    }

    expect($this->run->fresh()->turn_state)->toBe(TurnState::Running)
        ->and(TaskLog::query()->where('yak_task_id', $this->task->id)->count())->toBe(0);
    Event::assertNotDispatched(TurnCompleted::class);
});

it('does not let an older runner start line become current', function () {
    $this->thread->forceFill(['runner_generation' => 3])->save();

    app(ThreadDriver::class)->handle(threadEntry($this->thread, ['type' => 'runner', 'event' => 'started'], generation: 2));

    expect($this->thread->fresh()->runner_generation)->toBe(3);
});

it('keeps a lock that belongs to another turn when this turn finishes', function () {
    $other = TaskRun::factory()->create([
        'yak_task_id' => $this->task->id,
        'thread_id' => $this->thread->id,
        'turn_state' => TurnState::Completed,
    ]);
    $this->thread->forceFill(['lock_turn_id' => $other->id])->save();

    driveThread($this->thread, threadTurnLines());

    expect($this->run->fresh()->turn_state)->toBe(TurnState::Completed)
        ->and($this->thread->fresh()->lock_turn_id)->toBe($other->id)
        ->and($this->thread->fresh()->lock_holder)->toBe(ThreadLockHolder::Sandbox);
});

function runnerExit(int $generation = 1, bool $fromBroker = true): StreamEntry
{
    static $counter = 5000;
    $counter++;

    return new StreamEntry(test()->thread->channel(), $counter . '-0', $generation, $counter, ['type' => 'broker', 'event' => 'runner_exited', 'exit_code' => 1], $fromBroker);
}

it('counts restarts on the turn and waits before the second one', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id])->save();

    app(ThreadDriver::class)->handle(runnerExit());
    expect($this->run->fresh()->restart_count)->toBe(1)
        ->and(array_column($streams->commandsFor($this->thread->channel()), 'type'))->toBe(['kill', 'start_runner', 'turn']);

    // The new runner comes up, then dies again: the second restart waits for its backoff.
    app(ThreadDriver::class)->handle(threadEntry($this->thread, ['type' => 'runner', 'event' => 'started'], generation: 2));
    app(ThreadDriver::class)->handle(runnerExit(2));

    expect($this->run->fresh()->restart_count)->toBe(2)
        ->and($this->run->fresh()->restart_due_at)->not->toBeNull()
        ->and($streams->commandsFor($this->thread->channel()))->toHaveCount(3);

    $this->travel(6)->seconds();
    app(ThreadDriver::class)->tick();

    expect($streams->commandsFor($this->thread->channel()))->toHaveCount(6)
        ->and($this->run->fresh()->restart_due_at)->toBeNull();
});

it('fails the turn and releases the lock past the restart cap', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id])->save();
    $this->run->forceFill(['restart_count' => ThreadDriver::MAX_RESTARTS])->save();

    app(ThreadDriver::class)->handle(runnerExit());

    expect($this->run->fresh()->turn_state)->toBe(TurnState::Failed)
        ->and($this->thread->fresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and($this->thread->fresh()->lock_turn_id)->toBeNull()
        ->and(array_column($streams->commandsFor($this->thread->channel()), 'type'))->toBe(['kill']);
    Event::assertDispatched(TurnFailed::class, 1);
});

it('does not restart twice for the same loss', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id])->save();
    $this->run->forceFill(['restart_count' => 1, 'restart_due_at' => now()->addSeconds(5)])->save();

    app(ThreadDriver::class)->handle(runnerExit());

    expect($this->run->fresh()->restart_count)->toBe(1)
        ->and($streams->commandsFor($this->thread->channel()))->toBe([]);
});

it('ignores a no_runner for a uuid the turn is not waiting on', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => ClaudeAccount::factory()->create()->id])->save();

    app(ThreadDriver::class)->handle(new StreamEntry($this->thread->channel(), '6001-0', 1, 6001, ['type' => 'broker', 'event' => 'no_runner', 'uuid' => 'someone-elses'], true));
    app(ThreadDriver::class)->handle(new StreamEntry($this->thread->channel(), '6002-0', 1, 6002, ['type' => 'broker', 'event' => 'no_runner'], true));

    expect($streams->commandsFor($this->thread->channel()))->toBe([])
        ->and($this->run->fresh()->restart_count)->toBe(0);

    app(ThreadDriver::class)->handle(new StreamEntry($this->thread->channel(), '6003-0', 1, 6003, ['type' => 'broker', 'event' => 'no_runner', 'uuid' => 'prompt-1'], true));

    expect($this->run->fresh()->restart_count)->toBe(1);
});

it('ignores a runner exit from an older generation', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['runner_generation' => 2])->save();

    app(ThreadDriver::class)->handle(runnerExit(1));

    expect($streams->commandsFor($this->thread->channel()))->toBe([])
        ->and($this->run->fresh()->restart_count)->toBe(0);
});

it('fails the turn of a thread whose entry cannot be handled, and releases its lock', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);

    app(ThreadDriver::class)->failTurnOf(threadEntry($this->thread, ['type' => 'poison']));

    expect($this->run->fresh()->turn_state)->toBe(TurnState::Failed)
        ->and($this->thread->fresh()->lock_holder)->toBe(ThreadLockHolder::None)
        ->and(array_column($streams->commandsFor($this->thread->channel()), 'type'))->toBe(['kill']);
    Event::assertDispatched(TurnFailed::class, 1);
});

it('fails the turn instead of throwing when the account is gone at restart time', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $this->thread->forceFill(['claude_account_id' => null])->save();
    $this->run->forceFill(['restart_due_at' => now()->subSecond(), 'restart_count' => 1])->save();

    app(ThreadDriver::class)->tick();

    expect($this->run->fresh()->turn_state)->toBe(TurnState::Failed)
        ->and($this->thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::NoValidAccount)
        ->and($this->thread->fresh()->lock_holder)->toBe(ThreadLockHolder::None);
});

it('keeps redispatching completions when one thread cannot be restarted', function () {
    $this->thread->forceFill(['claude_account_id' => null])->save();
    $this->run->forceFill(['restart_due_at' => now()->subSecond()])->save();
    $other = Thread::factory()->paused()->create();
    $otherTask = YakTask::factory()->running()->create(['thread_id' => $other->id]);
    $lost = TaskRun::factory()->create([
        'yak_task_id' => $otherTask->id,
        'thread_id' => $other->id,
        'turn_state' => TurnState::Completed,
        'completion_dispatched_at' => null,
    ]);

    app(ThreadDriver::class)->tick();

    expect($lost->fresh()->completion_dispatched_at)->not->toBeNull();
});

it('revokes the proxy placeholder when the current runner exits', function () {
    $streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $streams);
    $account = ClaudeAccount::factory()->create();
    $this->thread->forceFill(['claude_account_id' => $account->id, 'sandbox_container' => 'yak-thread-1'])->save();
    $this->run->forceFill(['restart_count' => ThreadDriver::MAX_RESTARTS])->save();
    $placeholders = app(AuthProxyPlaceholders::class);
    $placeholder = $placeholders->issueForThread($this->thread, $account);

    app(ThreadDriver::class)->handle(runnerExit());

    expect($placeholders->resolve($placeholder))->toBeNull();
});
