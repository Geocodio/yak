<?php

use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadAgentStatus;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Listeners\DispatchAfterTurnWork;
use App\Listeners\PushTurnFinished;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\RunnerRecovery;
use App\Services\Threads\ThreadAgents;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadRequestStatus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

/**
 * A runner that takes every command: recovery restarts it without a sandbox to exec in.
 */
function ignoreRunnerCommands(): void
{
    app()->instance(ThreadCommands::class, Mockery::mock(ThreadCommands::class)->shouldIgnoreMissing());
}

function brokerLine(Thread $thread, int $sequence, array $line, int $generation = 1): void
{
    app(ThreadDriver::class)->handle(app(BrokerStreams::class)->pushOut('threads:' . $thread->id, $generation, $sequence, $line, fromBroker: true));
}

it('fails the running agents when their runner exits', function () {
    ignoreRunnerCommands();
    [$thread] = runningTurnFor();
    $running = ThreadAgent::factory()->count(2)->create(['thread_id' => $thread->id]);
    $done = ThreadAgent::factory()->finished()->create(['thread_id' => $thread->id]);

    brokerLine($thread, 1, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1, 'exit_code' => 137]);

    expect($running->map->refresh()->pluck('status')->unique()->all())->toBe([ThreadAgentStatus::Failed])
        ->and($running[0]->summary)->toBe(ThreadAgents::RUNNER_LOST)
        ->and($running[0]->finished_at)->not->toBeNull()
        ->and($done->refresh()->status)->toBe(ThreadAgentStatus::Completed)
        ->and(ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'agent.finished')->count())->toBe(2)
        ->and(ThreadAgent::hasRunning($thread->id))->toBeFalse();
});

it('leaves the agents of a newer runner alone when an old one exits', function () {
    [$thread] = runningTurnFor();
    $thread->forceFill(['runner_generation' => 2])->save();
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    brokerLine($thread, 1, ['type' => 'broker', 'event' => 'runner_exited', 'generation' => 1], generation: 1);

    expect($agent->refresh()->status)->toBe(ThreadAgentStatus::Running);
});

it('fails the running agents when the broker restarts or the runner is not there', function (string $event) {
    ignoreRunnerCommands();
    [$thread] = runningTurnFor();
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    brokerLine($thread, 1, ['type' => 'broker', 'event' => $event, 'uuid' => 'whatever']);

    expect($agent->refresh()->status)->toBe(ThreadAgentStatus::Failed);
})->with(['restarted', 'broker_started', 'no_runner']);

it('does not fail agents for a no_runner line of an older generation', function () {
    [$thread] = runningTurnFor();
    $thread->forceFill(['runner_generation' => 3])->save();
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    brokerLine($thread, 1, ['type' => 'broker', 'event' => 'no_runner', 'uuid' => 'x'], generation: 2);

    expect($agent->refresh()->status)->toBe(ThreadAgentStatus::Running);
});

it('fails the agents of a thread whose runner is gone on the next tick and keeps the others', function () {
    [$gone] = runningTurnFor();
    $gone->forceFill(['runner_state' => RunnerState::None])->save();
    [$alive] = runningTurnFor();
    $orphan = ThreadAgent::factory()->create(['thread_id' => $gone->id]);
    $healthy = ThreadAgent::factory()->create(['thread_id' => $alive->id]);

    app(ThreadDriver::class)->tick();

    expect($orphan->refresh()->status)->toBe(ThreadAgentStatus::Failed)
        ->and($healthy->refresh()->status)->toBe(ThreadAgentStatus::Running);
});

it('fails the agents when the runner of a running turn is restarted', function () {
    [$thread, $run] = runningTurnFor(['prompt_uuid' => 'prompt-1', 'prompt' => 'Do it']);
    $thread->forceFill(['runner_state' => RunnerState::None])->save();
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id]);
    $commands = Mockery::mock(ThreadCommands::class);
    $commands->shouldReceive('kill', 'startRunner', 'turn')->andReturnNull();
    app()->instance(ThreadCommands::class, $commands);

    app(RunnerRecovery::class)->recover($thread);

    expect($agent->refresh()->status)->toBe(ThreadAgentStatus::Failed);
});

it('sends the stop command of one agent to the runner', function () {
    [$thread] = runningTurnFor();

    app(ThreadCommands::class)->stopAgent($thread, 'a123');

    expect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->toBe([['type' => 'stop_agent', 'taskId' => 'a123']]);
});

it('tells the runner whether subagents are on and which nested option to use', function (bool $subagents, bool $nested) {
    config(['yak.threads.subagents' => $subagents, 'yak.threads.subagents_forward_nested' => $nested]);
    $thread = Thread::factory()->create(['sandbox_container' => 'thread-1', 'sandbox_state' => 'running', 'model' => 'opus']);
    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('issue')->andReturn('yakph-test');
    app(SandboxManager::class)->queueExecResult(Process::result("2: eth0    inet 10.100.0.7/24 scope global eth0\n"));

    (new ThreadCommands(app(BrokerStreams::class), app(SandboxManager::class), $placeholders, app(ClaudeModelCatalog::class)))
        ->startRunner($thread, ClaudeAccount::factory()->create(), null, 'x');

    $start = app(BrokerStreams::class)->commands('threads:' . $thread->id)[0]['start'];

    expect($start['subagents'])->toBe($subagents)->and($start['forwardNestedSubagentToolCalls'])->toBe($nested);
})->with([[true, true], [true, false], [false, false]]);

it('turns subagents on in testing and off unless the environment says so', function () {
    expect(config('yak.threads.subagents'))->toBeTrue();
});

function agentFollowUp(Thread $thread, TurnState $state = TurnState::Completed): TaskRun
{
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'kind' => TaskRunKind::AgentFollowUp, 'turn_state' => $state, 'driver_user_id' => null]);
}

it('sends no push for Claude replying to one agent while another still runs, and one when none does', function () {
    $thread = Thread::factory()->create();
    $run = agentFollowUp($thread);
    $running = ThreadAgent::factory()->create(['thread_id' => $thread->id]);
    $push = Mockery::mock(ThreadPush::class);
    $push->shouldReceive('send')->once()->with(PushEvent::TurnFinished, Mockery::any(), Mockery::any(), Mockery::type('string'), "run-{$run->id}");
    $recipients = Mockery::mock(PushRecipients::class);
    $recipients->shouldReceive('forTurn')->andReturn([]);
    $listener = new PushTurnFinished($push, $recipients);

    $listener->handleCompleted(new TurnCompleted($run));
    $running->update(['status' => ThreadAgentStatus::Completed, 'finished_at' => now()]);
    $listener->handleCompleted(new TurnCompleted($run));
});

it('holds the after-turn work of a reply to one agent until the last agent has finished', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    $run = agentFollowUp($thread);
    $running = ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    app(DispatchAfterTurnWork::class)->handle(new TurnCompleted($run));
    Queue::assertNothingPushed();

    $running->update(['status' => ThreadAgentStatus::Completed, 'finished_at' => now()]);
    app(DispatchAfterTurnWork::class)->handle(new TurnCompleted($run));
    Queue::assertPushed(FinishFollowUpTurnJob::class, fn (FinishFollowUpTurnJob $job): bool => $job->taskRunId === $run->id);
});

it('still announces a failed reply to an agent while others run', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    $run = agentFollowUp($thread, TurnState::Failed);
    ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    app(DispatchAfterTurnWork::class)->handle(new TurnFailed($run));

    Queue::assertPushed(FinishFollowUpTurnJob::class);
});

it('lists a thread as working while an agent runs and as done once it has finished', function () {
    $thread = Thread::factory()->create();
    YakTask::factory()->create(['thread_id' => $thread->id, 'status' => 'success']);
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    expect(ThreadRequestStatus::for($thread)['key'])->toBe('working')
        ->and(ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), ['working'])->pluck('id')->all())->toBe([$thread->id])
        ->and(ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), ['done'])->count())->toBe(0);

    $agent->update(['status' => ThreadAgentStatus::Completed, 'finished_at' => now()]);

    expect(ThreadRequestStatus::for($thread)['key'])->toBe('done')
        ->and(ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), ['working'])->count())->toBe(0)
        ->and(ThreadRequestStatus::scope(Thread::query()->withoutGlobalScopes(), ['done'])->pluck('id')->all())->toBe([$thread->id]);
});

it('stops a runaway turn without stopping the agents or giving them a time limit', function () {
    Event::fake([TurnFailed::class]);
    [$thread, $run] = runningTurnFor();
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id, 'started_at' => now()->subDays(2)]);

    $this->travel(241)->minutes();
    app(ThreadDriver::class)->tick();

    $types = collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->pluck('type')->all();

    expect($run->refresh()->turn_state)->toBe(TurnState::StoppedByGuard)
        ->and($types)->toContain('interrupt')->not->toContain('stop_agent')
        ->and($agent->refresh()->status)->toBe(ThreadAgentStatus::Running);
});
