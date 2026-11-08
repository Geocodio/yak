<?php

use App\DataTransferObjects\StreamEntry;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\TurnResult;
use Illuminate\Support\Facades\Event;

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

function threadEntry(Thread $thread, array $line, ?int $sequence = null, int $generation = 1): StreamEntry
{
    static $counter = 0;
    $sequence ??= ++$counter;

    return new StreamEntry($thread->channel(), $sequence . '-0', $generation, $sequence, $line);
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
