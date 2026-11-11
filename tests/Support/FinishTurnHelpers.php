<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadShell;
use Mockery\MockInterface;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/ThreadTurnsHelpers.php';

/**
 * A `result` line of the kind the runner reports at the end of a turn.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function resultEvent(string $summary = 'Done', array $overrides = []): array
{
    return array_merge([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'result' => $summary,
        'session_id' => 'sess_turn',
    ], $overrides);
}

/**
 * Wires the collaborators of an after-turn job: a thread sandbox that is
 * awake, and the task-sandbox fake standing in for the thread's shell.
 *
 * @return array{sandbox: MockInterface, shell: FakeSandboxManager}
 */
function fakeAfterTurnCollaborators(?SandboxWake $wake = null, ?FakeSandboxManager $shell = null): array
{
    $sandbox = Mockery::mock(ThreadSandbox::class);
    $sandbox->shouldReceive('ensureAwake')->andReturn($wake ?? SandboxWake::ready())->byDefault();
    $sandbox->shouldReceive('pauseIfIdle')->byDefault();
    $shell ??= new FakeSandboxManager;

    app()->instance(ThreadSandbox::class, $sandbox);
    app()->bind(ThreadShell::class, fn () => $shell);

    return ['sandbox' => $sandbox, 'shell' => $shell];
}

/**
 * A task with an ended turn on its thread, ready for its finisher.
 *
 * @param  array<string, mixed>|false  $resultEvent  false leaves the turn without a result line
 * @param  array<string, mixed>  $taskAttributes
 * @param  array<string, mixed>  $repositoryAttributes
 * @return array{0: YakTask, 1: TaskRun, 2: Thread}
 */
function endedTurn(array|false $resultEvent = [], array $taskAttributes = [], array $repositoryAttributes = [], TaskRunKind $kind = TaskRunKind::Initial, TurnState $state = TurnState::Completed): array
{
    $repository = Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo', ...$repositoryAttributes]);
    $thread = Thread::factory()->create(['repo' => $repository->slug, 'sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-test']);
    $task = YakTask::factory()->create([
        'repo' => $repository->slug,
        'source' => 'slack',
        'status' => TaskStatus::Running,
        'branch_name' => 'yak/test-1',
        'thread_id' => $thread->id,
        ...$taskAttributes,
    ]);
    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'kind' => $kind,
        'turn_state' => $state,
        'last_result_event' => $resultEvent === false ? null : ($resultEvent === [] ? resultEvent() : $resultEvent),
        'assistant_turns' => 3,
        'recorded_cost_usd' => 0.25,
    ]);

    return [$task, $run, $thread];
}

function runFinisher(TaskRun $run, string $job = FinishRunYakTurnJob::class): void
{
    (new $job($run->id))->handle(app(ThreadSandbox::class), app(SandboxManager::class));
}

/**
 * A pending task on a thread, with the thread's shell and runner faked.
 *
 * @param  array<string, mixed>  $taskAttributes
 * @return array{task: YakTask, thread: Thread, shell: FakeSandboxManager, sent: array<int, array<string, string>>}
 */
function pendingRunYak(array $taskAttributes = [], ?FakeSandboxManager $shell = null): array
{
    $fakes = fakeTurnCollaborators();
    $sent = [];
    $fakes['commands']->shouldReceive('startRunner')->andReturnUsing(function () use (&$sent) {
        $sent[] = ['command' => 'start_runner'];
    });
    $fakes['commands']->shouldReceive('turn')->andReturnUsing(function ($thread, $uuid, $text) use (&$sent) {
        $sent[] = ['command' => 'turn', 'text' => $text];
    });

    [$thread, $task] = threadWithTask();
    Thread::query()->whereKey($thread->id)->update(['sandbox_state' => 'running']);
    Repository::factory()->create(['slug' => 'test-repo', 'path' => '/home/yak/repos/test-repo', 'default_branch' => 'main']);
    $task->update(['repo' => 'test-repo', 'status' => TaskStatus::Pending, 'source' => 'slack', 'attempts' => 0, ...$taskAttributes]);

    $shell ??= new FakeSandboxManager;
    app()->bind(ThreadShell::class, fn () => $shell);

    return ['task' => $task->refresh(), 'thread' => $thread, 'shell' => $shell, 'sent' => &$sent];
}

/**
 * Ends a turn for an existing task and runs its finisher over the given shell.
 *
 * @param  class-string  $job
 * @param  string|array<string, mixed>  $result  a summary, or a full result event
 */
function finishTaskTurn(string $job, YakTask $task, FakeSandboxManager $shell, string|array $result, TaskRunKind $kind = TaskRunKind::FollowUp, TurnState $state = TurnState::Completed): TaskRun
{
    fakeAfterTurnCollaborators(shell: $shell);
    $thread = Thread::factory()->create(['repo' => $task->repo, 'sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-test']);
    YakTask::query()->whereKey($task->id)->update(['thread_id' => $thread->id, 'status' => TaskStatus::Running->value]);
    $task->refresh();

    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'kind' => $kind,
        'turn_state' => $state,
        'last_result_event' => is_array($result) ? $result : resultEvent($result),
        'assistant_turns' => 2,
        'recorded_cost_usd' => 0.01,
    ]);

    runFinisher($run, $job);

    return $run;
}
