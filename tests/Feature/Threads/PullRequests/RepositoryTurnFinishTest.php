<?php

use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Jobs\AfterTurn\FinishRepositoryTurnJob;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

function repositoryEventTurn(array $resultEvent): array
{
    fakeAfterTurnCollaborators();
    Repository::factory()->create(['slug' => 'acme/web', 'github_full_name' => 'acme/web', 'default_branch' => 'main', 'ci_system' => 'github_actions']);
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-test']);
    $task = YakTask::factory()->create(['repo' => 'acme/web', 'status' => TaskStatus::Success, 'thread_id' => $thread->id]);
    $web = ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/web', 'sandbox_path' => '/extras/web', 'branch' => 'yak/x-web']);
    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'kind' => TaskRunKind::RepositoryEvent,
        'turn_state' => TurnState::Completed,
        'last_result_event' => $resultEvent,
    ]);

    return [$task, $run, $web];
}

function queueExtraAheadOfOrigin(): void
{
    $sandboxes = app(SandboxManager::class);
    $sandboxes->queueExecResult(Process::result());
    $sandboxes->queueExecResult(Process::result('1'));
    $sandboxes->queueExecResult(Process::result());
}

it('publishes the extra repo although the head task already succeeded', function () {
    Queue::fake();
    config()->set('yak.channels.github.installation_id', 0);
    [$task, $run, $web] = repositoryEventTurn(resultEvent());
    queueExtraAheadOfOrigin();

    event(new TurnCompleted($run));
    Queue::assertPushed(FinishRepositoryTurnJob::class, fn ($job) => $job->taskRunId === $run->id);
    runFinisher($run, FinishRepositoryTurnJob::class);

    expect($web->refresh()->ci_status)->toBe('running')->and($task->refresh()->status)->toBe(TaskStatus::Success);
    $pushed = collect(app(SandboxManager::class)->calls)->contains(fn ($call) => str_contains(implode(' ', $call['arguments']['command'] ?? []), 'push origin'));
    expect($pushed)->toBeTrue();
});

it('publishes nothing for an errored turn and leaves the task alone', function () {
    [$task, $run, $web] = repositoryEventTurn(resultEvent('Broke', ['is_error' => true, 'subtype' => 'error_during_execution']));
    queueExtraAheadOfOrigin();

    runFinisher($run, FinishRepositoryTurnJob::class);

    expect($web->refresh()->ci_status)->toBeNull()->and($task->refresh()->status)->toBe(TaskStatus::Success);
});
