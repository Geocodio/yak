<?php

use App\Enums\TaskRunKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\FinishRunYakTurnJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->thread = Thread::factory()->paused()->create();
    $this->task = YakTask::factory()->running()->create(['thread_id' => $this->thread->id]);
});

function turnFor(YakTask $task, array $attributes = []): TaskRun
{
    return TaskRun::factory()->create($attributes + [
        'yak_task_id' => $task->id,
        'thread_id' => $task->thread_id,
        'kind' => TaskRunKind::Initial,
        'turn_state' => TurnState::Completed,
    ]);
}

it('dispatches the finisher for a completed initial turn', function () {
    $run = turnFor($this->task);

    event(new TurnCompleted($run));

    Queue::assertPushed(FinishRunYakTurnJob::class, fn ($job) => $job->taskRunId === $run->id && $job->queue === 'yak-claude');
});

it('dispatches the finisher for a failed clarification turn', function () {
    $run = turnFor($this->task, ['kind' => TaskRunKind::Clarification, 'turn_state' => TurnState::Failed]);

    event(new TurnFailed($run));

    Queue::assertPushed(FinishRunYakTurnJob::class);
});

it('ignores a turn that was already handled', function () {
    $run = turnFor($this->task, ['completion_dispatched_at' => now()]);

    event(new TurnCompleted($run));

    Queue::assertNothingPushed();
});

it('dispatches once when the event repeats', function () {
    $run = turnFor($this->task);

    event(new TurnCompleted($run));
    event(new TurnCompleted($run));

    Queue::assertPushed(FinishRunYakTurnJob::class, 1);
});

it('ignores a turn the runaway guard stopped', function () {
    $run = turnFor($this->task, ['turn_state' => TurnState::StoppedByGuard]);

    event(new TurnFailed($run));

    Queue::assertNothingPushed();
});

it('gives the claim back when the queue push fails so the work is sent again', function () {
    $run = turnFor($this->task);
    $this->mock(Illuminate\Contracts\Bus\Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

    expect(fn () => event(new TurnCompleted($run)))->toThrow(RuntimeException::class);

    expect($run->fresh()->completion_dispatched_at)->toBeNull();
});
