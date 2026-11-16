<?php

use App\Enums\RunnerState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadRetryReset;
use App\Services\Threads\ThreadSandbox;

function retryResetTask(array $threadAttributes = [], bool $ran = true): array
{
    $thread = Thread::factory()->create($threadAttributes);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    if ($ran) {
        TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id]);
    }

    return [$thread, $task];
}

it('marks a thread that already ran and stops a live runner', function () {
    [$thread, $task] = retryResetTask(['runner_state' => RunnerState::Idle]);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('stop')->once();

    (new ThreadRetryReset($sandbox))->prepare($task);

    expect($thread->fresh()->fresh_session_pending)->toBeTrue();
});

it('marks without stopping when no runner is live', function () {
    [$thread, $task] = retryResetTask();
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldNotReceive('stop');

    (new ThreadRetryReset($sandbox))->prepare($task);

    expect($thread->fresh()->fresh_session_pending)->toBeTrue();
});

it('leaves a thread that never ran untouched', function () {
    [$thread, $task] = retryResetTask(ran: false);
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldNotReceive('stop');

    (new ThreadRetryReset($sandbox))->prepare($task);

    expect($thread->fresh()->fresh_session_pending)->toBeFalse();
});
