<?php

use App\Enums\RunnerState;
use App\Enums\TaskRunKind;
use App\Enums\ThreadAgentStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Exceptions\ThreadActionRefused;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Listeners\DispatchAfterTurnWork;
use App\Models\TaskRun;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\StopStack;
use App\Services\Tasks\TaskActions;
use App\Services\Teleport\TeleportDownPreparer;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadAgents;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadRequestStatus;
use App\Services\Threads\ThreadTurns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../../../Support/AgentFixtureHelpers.php';

/**
 * Plays a fixture with its own spacing in time, ticking the driver after every line. Lines from `$fromMilliseconds` on
 * are pushed `$shiftMilliseconds` later, as if the agents had worked that much longer.
 */
function playAgentFixtureTimed(Thread $thread, string $name, int $shiftMilliseconds = 0, int $fromMilliseconds = 0): void
{
    $lines = agentFixtureLines($name);
    $base = CarbonImmutable::now();
    $streams = app(BrokerStreams::class);
    $driver = app(ThreadDriver::class);

    foreach ($lines as $index => $line) {
        test()->travelTo($base->addMilliseconds((int) $line['_ms'] + ((int) $line['_ms'] >= $fromMilliseconds ? $shiftMilliseconds : 0)));
        $driver->handle($streams->pushOut('threads:' . $thread->id, 1, $index + 1, $line));
        $driver->tick();
    }
}

function agentRunsOf(Thread $thread, ?TaskRunKind $kind = null): int
{
    return TaskRun::query()->where('thread_id', $thread->id)->when($kind !== null, fn ($query) => $query->where('kind', $kind))->count();
}

it('does not let the steps of an agent hold the turn open', function () {
    [$thread, $run] = agentFixtureTurn();

    playAgentFixtureTimed($thread, 'background-agents-session-state');
    test()->travel(31)->seconds();
    app(ThreadDriver::class)->tick();

    // The agents' replies come inside the grace window and are part of the person's turn, which ends once.
    expect($run->refresh()->turn_state)->toBe(TurnState::Completed)
        ->and(agentRunsOf($thread, TaskRunKind::AgentFollowUp))->toBe(0)
        ->and(ThreadAgent::query()->pluck('follow_up_run_id')->unique()->all())->toBe([$run->id]);
});

it('ends the turn and opens a follow-up when agents work long after the reply', function () {
    [$thread, $run] = agentFixtureTurn();

    // The agents' first tool calls open right after the reply; their results come five minutes later.
    playAgentFixtureTimed($thread, 'background-agents-session-state', shiftMilliseconds: 300_000, fromMilliseconds: 5_000);
    test()->travel(31)->seconds();
    app(ThreadDriver::class)->tick();

    $followUp = TaskRun::query()->where('thread_id', $thread->id)->where('kind', TaskRunKind::AgentFollowUp)->sole();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed)
        ->and($followUp->turn_state)->toBe(TurnState::Completed)
        ->and(TaskRun::query()->where('turn_state', TurnState::StoppedByGuard)->exists())->toBeFalse()
        ->and(ThreadAgent::query()->pluck('follow_up_run_id')->unique()->all())->toBe([$followUp->id]);
});

it('opens a follow-up for an orphaned nested agent that finishes after its parent was stopped', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixtureTimed($thread, 'agent-parent-stopped', shiftMilliseconds: 120_000, fromMilliseconds: 20_000);

    $child = ThreadAgent::query()->where('depth', 2)->sole();

    expect(agentRunsOf($thread, TaskRunKind::AgentFollowUp))->toBeGreaterThan(0)
        ->and($child->follow_up_run_id)->not->toBeNull();
});

it('does not open a follow-up for agents it failed itself', function () {
    Event::fake([TurnStarted::class]);
    [$thread, $run] = agentFixtureTurn();
    $run->update(['turn_state' => TurnState::Completed]);
    ThreadAgent::factory()->count(2)->create(['thread_id' => $thread->id]);
    app(ThreadAgents::class)->failAll($thread);

    playAgentFixture($thread, 'natural-turn-end', 0, 4);

    expect(agentRunsOf($thread, TaskRunKind::AgentFollowUp))->toBe(0)
        ->and(ThreadAgent::query()->where('needs_follow_up', false)->count())->toBe(2);
    Event::assertNotDispatched(TurnStarted::class);
});

it('keeps a reply a person wrote into from being quiet', function () {
    $thread = Thread::factory()->create();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'kind' => TaskRunKind::AgentFollowUp, 'turn_state' => TurnState::Completed]);
    ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    expect(ThreadAgents::isQuietTurn($run))->toBeTrue();

    ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $run->id]);

    expect(ThreadAgents::isQuietTurn($run))->toBeFalse();
});

it('announces a held back reply once when the last agent ends with no reply to come', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'kind' => TaskRunKind::AgentFollowUp, 'turn_state' => TurnState::Completed, 'driver_user_id' => null]);
    ThreadAgent::factory()->count(2)->create(['thread_id' => $thread->id]);

    app(DispatchAfterTurnWork::class)->handle(new TurnCompleted($run));
    expect($run->refresh()->quiet_unannounced_at)->not->toBeNull();
    Queue::assertNothingPushed();

    // One agent ending is not enough; the last one is.
    $first = ThreadAgent::query()->first();
    $first->update(['status' => ThreadAgentStatus::Completed, 'finished_at' => now()]);
    app(ThreadAgents::class)->failAll($thread);
    app(ThreadAgents::class)->failAll($thread);

    Queue::assertPushed(FinishFollowUpTurnJob::class, 1);
    expect($run->refresh()->quiet_unannounced_at)->toBeNull();
});

it('lets a later reply speak for the held back ones', function () {
    Queue::fake();
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    $quiet = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'kind' => TaskRunKind::AgentFollowUp, 'turn_state' => TurnState::Completed, 'quiet_unannounced_at' => now()]);
    $last = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id, 'kind' => TaskRunKind::AgentFollowUp, 'turn_state' => TurnState::Completed]);

    app(DispatchAfterTurnWork::class)->handle(new TurnCompleted($last));

    expect($quiet->refresh()->quiet_unannounced_at)->toBeNull();
    Queue::assertPushed(FinishFollowUpTurnJob::class, 1);
});

it('fails a running agent that the session no longer reports', function () {
    $thread = Thread::factory()->create();
    $background = ThreadAgent::factory()->create(['thread_id' => $thread->id]);
    $listed = ThreadAgent::factory()->create(['thread_id' => $thread->id]);
    $foreground = ThreadAgent::factory()->create(['thread_id' => $thread->id, 'is_background' => false]);
    $events = [];

    app(ThreadAgents::class)->apply($thread, null, ['type' => 'system', 'subtype' => 'background_tasks_changed', 'tasks' => [['task_id' => $listed->task_id]]], $events);

    expect($background->refresh()->status)->toBe(ThreadAgentStatus::Failed)
        ->and($background->summary)->toBe(ThreadAgents::LEFT_TASK_LIST)
        ->and($listed->refresh()->status)->toBe(ThreadAgentStatus::Running)
        ->and($foreground->refresh()->status)->toBe(ThreadAgentStatus::Running);

    app(ThreadAgents::class)->apply($thread, null, ['type' => 'system', 'subtype' => 'session_state_changed', 'state' => 'running'], $events);
    expect($listed->refresh()->status)->toBe(ThreadAgentStatus::Running);

    app(ThreadAgents::class)->apply($thread, null, ['type' => 'system', 'subtype' => 'session_state_changed', 'state' => 'idle'], $events);
    expect($listed->refresh()->status)->toBe(ThreadAgentStatus::Failed)
        ->and($foreground->refresh()->summary)->toBe(ThreadAgents::SESSION_IDLE)
        ->and(ThreadAgent::hasRunning($thread->id))->toBeFalse();
});

it('records a reply to an agent as failed and silent when the thread is blocked or not Yak\'s', function (callable $block) {
    Event::fake([TurnStarted::class, TurnFailed::class]);
    [$thread, $run] = agentFixtureTurn();
    $run->update(['turn_state' => TurnState::Completed]);
    $block($thread);
    $agents = ThreadAgent::factory()->finished()->count(3)->create(['thread_id' => $thread->id, 'task_run_id' => $run->id]);

    playAgentFixture($thread, 'natural-turn-end', 0, 4);

    $reply = TaskRun::query()->where('thread_id', $thread->id)->where('kind', TaskRunKind::AgentFollowUp)->sole();
    expect($reply->turn_state)->toBe(TurnState::Failed)
        ->and($reply->completion_event_emitted_at)->not->toBeNull()
        ->and($agents->map->refresh()->pluck('follow_up_run_id')->unique()->all())->toBe([$reply->id])
        ->and($thread->refresh()->lock_turn_id)->not->toBe((string) $reply->id);
    Event::assertNotDispatched(TurnStarted::class);
    Event::assertNotDispatched(TurnFailed::class);
})->with([
    'usage limit' => [fn (Thread $thread) => $thread->forceFill(['blocked_reason' => ThreadBlockReason::UsageLimit])->save()],
    'on a computer' => [fn (Thread $thread) => $thread->forceFill(['owner_side' => ThreadOwnerSide::Device])->save()],
]);

it('takes the account slot for a reply to an agent even when the account is full', function () {
    Event::fake([TurnStarted::class]);
    [$thread, $run] = agentFixtureTurn();
    $run->update(['turn_state' => TurnState::Completed]);
    $account = $thread->claudeAccount;
    $account->update(['max_concurrent_turns' => 1]);
    TaskRun::factory()->create(['claude_account_id' => $account->id, 'account_slot_held_at' => now(), 'turn_state' => TurnState::Running]);
    ThreadAgent::factory()->finished()->create(['thread_id' => $thread->id, 'task_run_id' => $run->id]);

    playAgentFixture($thread, 'natural-turn-end', 0, 4);

    $reply = TaskRun::query()->where('thread_id', $thread->id)->where('kind', TaskRunKind::AgentFollowUp)->sole();
    expect($reply->turn_state)->toBe(TurnState::Running)
        ->and($reply->account_slot_held_at)->not->toBeNull();
});

function stopCommandCount(Thread $thread): int
{
    return count(array_filter(app(BrokerStreams::class)->commands('threads:' . $thread->id), fn (array $command): bool => $command['type'] === 'stop_agent'));
}

it('stops the agents of a stopped stack, as the composer Stop does not', function () {
    [$thread] = runningTurnFor();
    ThreadAgent::factory()->count(2)->create(['thread_id' => $thread->id]);
    $commands = Mockery::mock(ThreadCommands::class)->shouldIgnoreMissing();
    app()->instance(ThreadCommands::class, $commands);

    app(ThreadTurns::class)->stop($thread);
    expect(ThreadAgent::query()->whereNotNull('stop_requested_at')->count())->toBe(0);

    $commands->shouldReceive('stopAgent')->twice();
    app(StopStack::class)->stop($thread);

    expect(ThreadAgent::query()->whereNotNull('stop_requested_at')->count())->toBe(2);
});

it('stops the agents before a task moves and before a thread moves to a computer', function () {
    [$thread] = runningTurnFor();
    $thread->forceFill(['runner_state' => RunnerState::Idle])->save();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::query()->where('thread_id', $thread->id)->update(['turn_state' => TurnState::Completed]);
    ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    $move = new ReflectionMethod(TaskActions::class, 'stopTurnBeforeMove');
    expect(fn () => $move->invoke(app(TaskActions::class), $task->refresh(), 'acme/other'))->toThrow(ThreadActionRefused::class);
    expect(stopCommandCount($thread))->toBe(1);

    ThreadAgent::query()->update(['stop_requested_at' => null]);
    $teleport = Teleport::factory()->down()->create(['thread_id' => $thread->id]);
    $settle = new ReflectionMethod(TeleportDownPreparer::class, 'settleTurn');
    expect($settle->invoke(app(TeleportDownPreparer::class), $teleport, $thread))->toBeFalse();
    expect(stopCommandCount($thread))->toBe(2);
});

it('gives the old behaviour with the subagents flag off', function () {
    [$thread, $run] = agentFixtureTurn();
    config(['yak.threads.subagents' => false]);
    $owner = User::factory()->create();
    $thread->forceFill(['owner_user_id' => $owner->id])->save();
    $stale = ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    playAgentFixture($thread, 'nested-agents-forwarded');

    expect(ThreadAgent::query()->count())->toBe(1)
        ->and(ThreadEvent::query()->where('type', 'like', 'agent.%')->count())->toBe(0)
        ->and(ThreadEvent::query()->where('type', 'entry')->get()->contains(fn (ThreadEvent $event): bool => isset($event->payload['agentId'])))->toBeFalse()
        ->and(agentRunsOf($thread, TaskRunKind::AgentFollowUp))->toBe(0)
        ->and(ThreadAgent::hasRunning($thread->id))->toBeFalse()
        ->and($thread->refresh()->isSandboxBusy())->toBeTrue();

    $run->update(['turn_state' => TurnState::Completed]);
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None])->save();
    TaskRun::query()->where('thread_id', $thread->id)->update(['turn_state' => TurnState::Completed]);

    expect($thread->refresh()->isSandboxBusy())->toBeFalse()
        ->and(ThreadRequestStatus::for($thread)['key'])->not->toBe('working');

    foreach ([['post', route('threads.agents.stop-all', $thread)], ['post', route('threads.agents.stop', [$thread, $stale->task_id])], ['post', route('threads.agents.messages.store', [$thread, $stale->task_id])], ['get', route('threads.agents.log', [$thread, $stale->task_id])]] as [$method, $url]) {
        $this->actingAs($owner)->{$method}($url)->assertNotFound();
    }
});

it('indexes the agents by status and thread for the orphan sweep', function () {
    expect(Schema::hasIndex('thread_agents', ['status', 'thread_id']))->toBeTrue()
        ->and(Schema::hasColumn('task_runs', 'quiet_unannounced_at'))->toBeTrue();
});
