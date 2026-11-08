<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadSessionKind;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Exceptions\SandboxCapacityReached;
use App\Jobs\FinishFollowUpTurnJob;
use App\Jobs\FinishResearchFollowUpTurnJob;
use App\Jobs\FinishResearchTurnJob;
use App\Jobs\FinishRetryTurnJob;
use App\Jobs\FinishThreadTurnJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadStreams;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    Queue::fake();
    Process::fake(['*' => Process::result('')]);

    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    $this->sandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $this->sandbox);

    $this->threadSandbox = Mockery::mock(ThreadSandbox::class);
    $this->threadSandbox->shouldReceive('ensureAwake')->andReturn('yak-thread-1');
    $this->threadSandbox->shouldReceive('pause')->byDefault();
    $this->app->instance(ThreadSandbox::class, $this->threadSandbox);

    Repository::factory()->create(['slug' => 'thread-repo', 'path' => '/home/yak/repos/thread-repo']);
    $this->account = ClaudeAccount::factory()->create();
    $this->thread = Thread::factory()->stopped()->create(['claude_account_id' => $this->account->id, 'repo' => 'thread-repo']);
    $this->thread->sessions()->create(['session_id' => 'session-1', 'kind' => ThreadSessionKind::Main]);
});

function rootThreadTaskOn(Thread $thread, array $attributes = []): YakTask
{
    return YakTask::factory()->success()->create($attributes + [
        'repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $thread->id, 'branch_name' => 'yak/ISSUE-1', 'session_id' => 'session-1',
    ]);
}

function threadFollowUpOf(YakTask $parent, array $attributes = []): YakTask
{
    return YakTask::factory()->pending()->create($attributes + [
        'repo' => $parent->repo, 'source' => $parent->source, 'parent_task_id' => $parent->id, 'branch_name' => $parent->branch_name,
        'session_id' => $parent->session_id, 'description' => 'Please also rename the helper',
    ]);
}

function startRunnerCommandFor(object $test): ?array
{
    return collect($test->streams->commandsFor($test->thread->channel()))->firstWhere('type', 'start_runner');
}

function treeCommandsOf(FakeSandboxManager $sandbox): array
{
    return array_values(array_filter($sandbox->commands, fn (string $command): bool => preg_match('/git (fetch|checkout|reset|clean|rebase)/', $command) === 1));
}

function endedThreadTurn(YakTask $task, TaskRunKind $kind, array $event, TurnState $state = TurnState::Completed): TaskRun
{
    return TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $task->thread_id ?? $task->rootTask()->thread_id,
        'kind' => $kind,
        'repo' => $task->repo,
        'turn_state' => $state,
        'last_result_event' => $event,
        'assistant_messages' => 2,
        'agent_ms' => 3000,
        'cost_usd' => 0.4,
    ]);
}

function threadResultEvent(string $text, bool $isError = false): array
{
    return ['type' => 'result', 'subtype' => $isError ? 'error_during_execution' : 'success', 'is_error' => $isError, 'result' => $text, 'session_id' => 'session-1', 'total_cost_usd' => 0.4];
}

function runThreadFinisher(FinishThreadTurnJob $job): void
{
    app()->call([$job, 'handle']);
}

it('a follow-up runs as a turn in its parent\'s thread', function () {
    $parent = rootThreadTaskOn($this->thread);
    $followUp = threadFollowUpOf($parent);

    (new RunFollowUpJob($followUp))->handle(new FakeAgentRunner);

    $run = $followUp->runs()->sole();
    expect($run->kind)->toBe(TaskRunKind::FollowUp)
        ->and($run->thread_id)->toBe($this->thread->id)
        ->and($run->prompt)->toContain('rename the helper')
        ->and($followUp->fresh()->thread_id)->toBe($this->thread->id)
        ->and($this->sandbox->createdContainers)->toBe([])
        ->and(array_column($this->streams->commandsFor($this->thread->channel()), 'type'))->toContain('turn');
});

it('a CI retry resets the tree to the pushed head before its turn', function () {
    $task = rootThreadTaskOn($this->thread, ['status' => TaskStatus::Retrying]);

    (new RetryYakJob($task, 'CI failed: test X'))->handle(new FakeAgentRunner);

    $commands = $this->sandbox->commands;
    $fetch = collect($commands)->search(fn ($command) => str_contains($command, "git fetch origin 'yak/ISSUE-1'"));
    $reset = collect($commands)->search(fn ($command) => str_contains($command, "git reset --hard 'origin/yak/ISSUE-1'"));
    $clean = collect($commands)->search(fn ($command) => str_contains($command, 'git clean -fd'));

    expect($fetch)->not->toBeFalse()
        ->and($reset)->toBeGreaterThan($fetch)
        ->and($clean)->toBeGreaterThan($reset)
        ->and($task->runs()->sole()->kind)->toBe(TaskRunKind::Retry);
});

it('a CI retry resumes the same session', function () {
    $task = rootThreadTaskOn($this->thread, ['status' => TaskStatus::Retrying]);

    (new RetryYakJob($task, 'CI failed'))->handle(new FakeAgentRunner);

    expect(startRunnerCommandFor($this)['start']['resume'])->toBe('session-1')
        ->and($task->runs()->sole()->starts_new_session)->toBeFalse();
});

it('a dashboard retry before CI starts a new session', function () {
    $task = YakTask::factory()->pending()->create([
        'repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $this->thread->id, 'session_id' => 'session-1', 'attempts_at_manual_retry' => 1,
    ]);

    (new RunYakJob($task))->handle(new FakeAgentRunner);

    $run = $task->runs()->sole();
    expect(startRunnerCommandFor($this)['start']['resume'])->toBeNull()
        ->and($run->starts_new_session)->toBeTrue();
});

it('the first session of a new thread is not a restart', function () {
    $thread = Thread::factory()->stopped()->create(['claude_account_id' => $this->account->id, 'repo' => 'thread-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $thread->id]);

    (new RunYakJob($task))->handle(new FakeAgentRunner);

    expect($task->runs()->sole()->starts_new_session)->toBeFalse();
});

it('research runs without touching the working tree', function () {
    $parent = rootThreadTaskOn($this->thread, ['mode' => TaskMode::Research, 'branch_name' => null]);
    endedThreadTurn($parent, TaskRunKind::Research, threadResultEvent('Earlier answer'));
    $followUp = threadFollowUpOf($parent, ['mode' => TaskMode::Research, 'branch_name' => null]);

    (new ResearchFollowUpJob($followUp))->handle(new FakeAgentRunner);

    expect(treeCommandsOf($this->sandbox))->toBe([])
        ->and($followUp->runs()->sole()->kind)->toBe(TaskRunKind::FollowUp)
        ->and($followUp->runs()->sole()->thread_id)->toBe($this->thread->id);

    $research = YakTask::factory()->pending()->create(['repo' => 'thread-repo', 'mode' => TaskMode::Research, 'source' => 'slack', 'thread_id' => $this->thread->id]);
    $this->sandbox->commands = [];
    $this->thread->update(['lock_holder' => 'none', 'lock_turn_id' => null]);

    (new ResearchYakJob($research))->handle(new FakeAgentRunner);

    expect(treeCommandsOf($this->sandbox))->toBe([])
        ->and($research->runs()->sole()->kind)->toBe(TaskRunKind::Research);
});

it('research on a thread with no turns starts from the latest default branch', function () {
    $research = YakTask::factory()->pending()->create(['repo' => 'thread-repo', 'mode' => TaskMode::Research, 'source' => 'slack', 'thread_id' => $this->thread->id]);

    (new ResearchYakJob($research))->handle(new FakeAgentRunner);

    expect($this->sandbox->commandsMatching('git reset --hard origin/'))->not->toBe([]);
});

it('a code task after research checks out its branch', function () {
    $parent = rootThreadTaskOn($this->thread, ['mode' => TaskMode::Research]);
    $followUp = threadFollowUpOf($parent);

    (new RunFollowUpJob($followUp))->handle(new FakeAgentRunner);

    expect($this->sandbox->commandsMatching("git checkout -f 'yak/ISSUE-1'"))->not->toBe([])
        ->and($this->sandbox->commandsMatching("git reset --hard 'origin/yak/ISSUE-1'"))->not->toBe([]);
});

it('waits for a free sandbox instead of failing when none is available', function () {
    $this->threadSandbox = Mockery::mock(ThreadSandbox::class);
    $this->threadSandbox->shouldReceive('ensureAwake')->andThrow(new SandboxCapacityReached);
    $this->app->instance(ThreadSandbox::class, $this->threadSandbox);
    $task = rootThreadTaskOn($this->thread, ['status' => TaskStatus::Retrying]);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once()->with(60);
    $job = new RetryYakJob($task, 'CI failed');
    $job->setJob($queueJob);
    $job->handle(new FakeAgentRunner);

    expect($task->fresh()->status)->toBe(TaskStatus::Retrying);
});

/*
|--------------------------------------------------------------------------
| Finishers
|--------------------------------------------------------------------------
*/

it('the follow-up finisher pushes and waits for CI', function () {
    $task = threadFollowUpOf(rootThreadTaskOn($this->thread), ['status' => TaskStatus::Running]);

    runThreadFinisher(new FinishFollowUpTurnJob(endedThreadTurn($task, TaskRunKind::FollowUp, threadResultEvent('Renamed the helper'))->id));

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($this->sandbox->commandsMatching('git push --force-with-lease'))->not->toBe([])
        ->and($this->sandbox->destroyedContainers)->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Progress);
});

it('the follow-up finisher resolves the task without a push when there are no commits', function () {
    $this->app->instance(IncusSandboxManager::class, $this->sandbox->setCommitCount(0));
    $task = threadFollowUpOf(rootThreadTaskOn($this->thread), ['status' => TaskStatus::Running]);

    runThreadFinisher(new FinishFollowUpTurnJob(endedThreadTurn($task, TaskRunKind::FollowUp, threadResultEvent('Nothing to change'))->id));

    expect($this->sandbox->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(ProcessCIResultJob::class);
});

it('the follow-up finisher fails the task on an error turn', function () {
    $task = threadFollowUpOf(rootThreadTaskOn($this->thread), ['status' => TaskStatus::Running]);

    runThreadFinisher(new FinishFollowUpTurnJob(endedThreadTurn($task, TaskRunKind::FollowUp, threadResultEvent('It broke', true), TurnState::Failed)->id));

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->error_log)->not->toBeEmpty();
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error);
});

it('the retry finisher force-pushes and waits for CI', function () {
    $task = rootThreadTaskOn($this->thread, ['status' => TaskStatus::Retrying]);

    runThreadFinisher(new FinishRetryTurnJob(endedThreadTurn($task, TaskRunKind::Retry, threadResultEvent('Fixed the test'))->id));

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->fresh()->result_summary)->toBe('Fixed the test')
        ->and($this->sandbox->commandsMatching('git push --force-with-lease'))->not->toBe([]);
});

it('the retry finisher answers without a push when there are no commits', function () {
    $this->app->instance(IncusSandboxManager::class, $this->sandbox->setCommitCount(0));
    $task = rootThreadTaskOn($this->thread, ['status' => TaskStatus::Retrying]);

    runThreadFinisher(new FinishRetryTurnJob(endedThreadTurn($task, TaskRunKind::Retry, threadResultEvent('A flake, no change needed'))->id));

    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($this->sandbox->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

it('the retry finisher fails the task on an error turn', function () {
    $task = rootThreadTaskOn($this->thread, ['status' => TaskStatus::Retrying]);

    runThreadFinisher(new FinishRetryTurnJob(endedThreadTurn($task, TaskRunKind::Retry, threadResultEvent('Still red', true), TurnState::Failed)->id));

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error);
});

it('the research finisher records the answer and reports it', function () {
    $task = rootThreadTaskOn($this->thread, ['mode' => TaskMode::Research, 'status' => TaskStatus::Running]);

    runThreadFinisher(new FinishResearchTurnJob(endedThreadTurn($task, TaskRunKind::Research, threadResultEvent('Three options exist'))->id));

    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($task->fresh()->result_summary)->toBe('Three options exist');
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

it('the research finisher fails the task on an error turn', function () {
    $task = rootThreadTaskOn($this->thread, ['mode' => TaskMode::Research, 'status' => TaskStatus::Running]);

    runThreadFinisher(new FinishResearchTurnJob(endedThreadTurn($task, TaskRunKind::Research, threadResultEvent('No luck', true), TurnState::Failed)->id));

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error);
});

it('the research follow-up finisher records the answer and reports it', function () {
    $parent = rootThreadTaskOn($this->thread, ['mode' => TaskMode::Research]);
    $task = threadFollowUpOf($parent, ['mode' => TaskMode::Research, 'status' => TaskStatus::Running]);

    runThreadFinisher(new FinishResearchFollowUpTurnJob(endedThreadTurn($task, TaskRunKind::FollowUp, threadResultEvent('Backoff is cheapest'))->id));

    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($task->fresh()->result_summary)->toBe('Backoff is cheapest');
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

it('the research follow-up finisher fails the task on an error turn', function () {
    $parent = rootThreadTaskOn($this->thread, ['mode' => TaskMode::Research]);
    $task = threadFollowUpOf($parent, ['mode' => TaskMode::Research, 'status' => TaskStatus::Running]);

    runThreadFinisher(new FinishResearchFollowUpTurnJob(endedThreadTurn($task, TaskRunKind::FollowUp, threadResultEvent('No luck', true), TurnState::Failed)->id));

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('the listener picks the finisher by kind and task mode', function (TaskRunKind $kind, TaskMode $mode, string $finisher) {
    $task = YakTask::factory()->running()->create(['repo' => 'thread-repo', 'mode' => $mode, 'thread_id' => $this->thread->id]);
    $run = endedThreadTurn($task, $kind, threadResultEvent('Done'));

    event(new TurnCompleted($run));

    Queue::assertPushed($finisher, fn ($job) => $job->taskRunId === $run->id);
})->with([
    'code follow-up' => [TaskRunKind::FollowUp, TaskMode::Fix, FinishFollowUpTurnJob::class],
    'research follow-up' => [TaskRunKind::FollowUp, TaskMode::Research, FinishResearchFollowUpTurnJob::class],
    'retry' => [TaskRunKind::Retry, TaskMode::Fix, FinishRetryTurnJob::class],
    'research' => [TaskRunKind::Research, TaskMode::Research, FinishResearchTurnJob::class],
]);
