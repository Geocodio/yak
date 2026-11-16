<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Schema;

it('adds the driver columns', function () {
    expect(Schema::hasColumns('threads', ['applied_generation', 'applied_sequence', 'runner_model', 'append_system_prompt', 'runner_restart_at']))->toBeTrue()
        ->and(Schema::hasColumns('task_runs', ['prompt_uuid', 'prompt', 'tracker_state', 'assistant_turns']))->toBeTrue()
        ->and(Schema::hasTable('thread_tool_calls'))->toBeTrue();
});

it('finds the running and active turn', function () {
    $thread = Thread::factory()->create();

    expect(ThreadTurns::runningTurn($thread))->toBeNull()
        ->and(ThreadTurns::activeTurn($thread))->toBeNull();

    $pending = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Pending]);

    expect(ThreadTurns::runningTurn($thread))->toBeNull()
        ->and(ThreadTurns::activeTurn($thread)->id)->toBe($pending->id);

    $running = TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Completed]);

    expect(ThreadTurns::runningTurn($thread)->id)->toBe($running->id)
        ->and(ThreadTurns::activeTurn($thread)->id)->toBe($running->id);
});
