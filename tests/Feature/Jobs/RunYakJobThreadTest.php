<?php

use App\Contracts\AgentRunner;
use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Jobs\FinishRunYakTurnJob;
use App\Jobs\ProcessCIResultJob;
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
    $this->threadSandbox->shouldReceive('startingTurn')->andReturnUsing(fn ($thread, Closure $startTurn) => $startTurn());
    $this->threadSandbox->shouldReceive('holdingSandbox')->andReturnUsing(fn ($thread, Closure $work) => $work());
    $this->threadSandbox->shouldReceive('pause')->byDefault();
    $this->app->instance(ThreadSandbox::class, $this->threadSandbox);

    $this->repository = Repository::factory()->create(['slug' => 'thread-repo', 'path' => '/home/yak/repos/thread-repo']);
    $this->account = ClaudeAccount::factory()->create();
    $this->thread = Thread::factory()->paused()->create(['claude_account_id' => $this->account->id, 'repo' => 'thread-repo']);
});

function resultEvent(string $text, bool $isError = false): array
{
    return ['type' => 'result', 'subtype' => $isError ? 'error_during_execution' : 'success', 'is_error' => $isError, 'result' => $text, 'session_id' => 'session-1', 'total_cost_usd' => 0.4];
}

function finishedTurn(YakTask $task, array $event, TaskRunKind $kind = TaskRunKind::Initial, TurnState $state = TurnState::Completed): TaskRun
{
    return TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $task->thread_id,
        'kind' => $kind,
        'repo' => $task->repo,
        'turn_state' => $state,
        'last_result_event' => $event,
        'assistant_messages' => 2,
        'agent_ms' => 3000,
        'cost_usd' => 0.4,
    ]);
}

function runFinisher(TaskRun $run): void
{
    app()->call([new FinishRunYakTurnJob($run->id), 'handle']);
}

it('writes a turn instead of running claude when threads are enabled', function () {
    $task = YakTask::factory()->pending()->create(['repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $this->thread->id]);
    $agent = Mockery::mock(AgentRunner::class);
    $agent->shouldNotReceive('run');

    (new RunYakJob($task))->handle($agent);

    $run = $task->runs()->sole();
    expect($run->kind)->toBe(TaskRunKind::Initial)
        ->and($run->turn_state)->toBe(TurnState::Running)
        ->and($run->prompt)->not->toBe('')
        ->and($task->fresh()->branch_name)->toStartWith('yak/')
        ->and(array_column($this->streams->commandsFor($this->thread->channel()), 'type'))->toContain('turn');
});

it('ends the job after writing the turn', function () {
    $task = YakTask::factory()->pending()->create(['repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $this->thread->id]);

    (new RunYakJob($task))->handle(new FakeAgentRunner);

    expect($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and($this->sandbox->destroyedContainers)->toBe([])
        ->and($this->sandbox->commandsMatching('git push'))->toBe([]);
    Queue::assertNotPushed(ProcessCIResultJob::class);
});

it('the finisher pushes and hands off to CI on success', function () {
    $task = YakTask::factory()->running()->create(['repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $this->thread->id]);
    $run = finishedTurn($task, resultEvent('Fixed the bug'));

    runFinisher($run);

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->fresh()->result_summary)->toBe('Fixed the bug')
        ->and($this->sandbox->commandsMatching('git push'))->not->toBe([])
        ->and($this->sandbox->destroyedContainers)->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Progress);
});

it('the finisher answers without a push when there are no commits', function () {
    $this->app->instance(IncusSandboxManager::class, $this->sandbox->setCommitCount(0));
    $task = YakTask::factory()->running()->create(['repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $this->thread->id]);

    runFinisher(finishedTurn($task, resultEvent('Nothing to change')));

    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($this->sandbox->commandsMatching('git push'))->toBe([]);
});

it('the finisher fails the task on an error turn', function () {
    $task = YakTask::factory()->running()->create(['repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $this->thread->id]);

    runFinisher(finishedTurn($task, resultEvent('It broke', isError: true), state: TurnState::Failed));

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->error_log)->not->toBeEmpty();
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error);
});

it('the finisher fails the task when a turn has no result', function () {
    $task = YakTask::factory()->running()->create(['repo' => 'thread-repo', 'source' => 'slack', 'thread_id' => $this->thread->id]);
    $run = finishedTurn($task, [], state: TurnState::Failed);
    $run->forceFill(['last_result_event' => null])->save();

    runFinisher($run);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('the finisher parks for clarification questions and pauses the sandbox', function () {
    $this->threadSandbox->shouldReceive('pause')->once();
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('scope')->toArray()]]) . "\n```";
    $task = YakTask::factory()->running()->create(['repo' => 'thread-repo', 'source' => 'linear', 'thread_id' => $this->thread->id]);

    runFinisher(finishedTurn($task, resultEvent("Need a decision.\n\n{$block}")));

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($task->fresh()->clarificationRoundCount())->toBe(1)
        ->and($this->sandbox->commandsMatching('git push'))->toBe([]);
});

it('the finisher leaves a cancelled task alone', function () {
    $task = YakTask::factory()->create(['repo' => 'thread-repo', 'status' => TaskStatus::Cancelled, 'thread_id' => $this->thread->id]);

    runFinisher(finishedTurn($task, resultEvent('Done')));

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('a clarification answer runs as a turn on the same thread', function () {
    $task = YakTask::factory()->withClarificationQuestions()->create([
        'repo' => 'thread-repo', 'source' => 'linear', 'thread_id' => $this->thread->id, 'session_id' => 'session-1', 'branch_name' => 'yak/ask',
    ]);
    $task->recordClarificationAnswers(['scope' => ['choices' => ['Small'], 'other' => null]], null, 'Michele');
    $task->update(['status' => TaskStatus::Pending]);

    (new RunYakJob($task->fresh()))->handle(new FakeAgentRunner);

    $run = $task->runs()->sole();
    expect($run->kind)->toBe(TaskRunKind::Clarification)
        ->and($run->thread_id)->toBe($this->thread->id)
        ->and($run->prompt)->toContain("Q: Which scope?\nA: Small")
        ->and($task->fresh()->clarificationAnswersAwaitingResume())->toBeNull();
});
