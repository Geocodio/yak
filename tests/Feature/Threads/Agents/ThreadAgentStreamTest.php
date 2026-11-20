<?php

use App\Enums\TaskRunKind;
use App\Enums\ThreadAgentKind;
use App\Enums\ThreadAgentStatus;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Http\Resources\ThreadAgentsData;
use App\Models\TaskRun;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Services\Threads\ThreadAgents;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadRequestStatus;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/AgentFixtureHelpers.php';

const BACKGROUND_FIXTURE = 'background-agents-session-state';

it('records background agents from task lines and keeps the thread working after the reply', function () {
    Event::fake([TurnStarted::class, TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = agentFixtureTurn();

    playAgentFixture($thread, BACKGROUND_FIXTURE, 0, agentFixtureIndexAfter(BACKGROUND_FIXTURE, fn (array $line): bool => ($line['type'] ?? null) === 'command_lifecycle' && ($line['state'] ?? null) === 'completed'));

    $agents = ThreadAgent::query()->where('thread_id', $thread->id)->orderBy('id')->get();
    expect($agents)->toHaveCount(2)
        ->and($agents[0]->task_id)->toBe('a657fe1a0f4bd68b8')
        ->and($agents[0]->kind)->toBe(ThreadAgentKind::Agent)
        ->and($agents[0]->agent_type)->toBe('general-purpose')
        ->and($agents[0]->description)->toBe('Background sleep A')
        ->and($agents[0]->is_background)->toBeTrue()
        ->and($agents[0]->depth)->toBe(1)
        ->and($agents[0]->parent_task_id)->toBeNull()
        ->and($agents[0]->task_run_id)->toBe($run->id)
        ->and($agents[0]->status)->toBe(ThreadAgentStatus::Running)
        ->and($agents[0]->tool_use_id)->toStartWith('toolu_');

    // Claude's reply is over; the agents are not, so the thread still works and the sandbox stays awake.
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed)
        ->and(ThreadRequestStatus::for($thread)['key'])->toBe('working')
        ->and($thread->refresh()->isSandboxBusy())->toBeTrue();
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
});

it('turns a task-notification reply into one AgentFollowUp run linked to the agents', function () {
    Event::fake([TurnStarted::class, TurnCompleted::class, TurnFailed::class]);
    [$thread, $run] = agentFixtureTurn();
    $firstResult = agentFixtureIndexAfter(BACKGROUND_FIXTURE, fn (array $line): bool => ($line['type'] ?? null) === 'result');

    playAgentFixture($thread, BACKGROUND_FIXTURE, 0, $firstResult + 1);
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();
    playAgentFixture($thread, BACKGROUND_FIXTURE, $firstResult + 1);

    $followUp = TaskRun::query()->where('thread_id', $thread->id)->where('kind', TaskRunKind::AgentFollowUp)->sole();
    $agents = ThreadAgent::query()->where('thread_id', $thread->id)->get();

    expect($followUp->turn_state)->toBe(TurnState::Running)
        ->and($followUp->prompt_uuid)->toBeNull()
        ->and($followUp->yak_task_id)->toBe($run->yak_task_id)
        ->and($followUp->claude_account_id)->toBe($run->claude_account_id)
        ->and($followUp->agent_started_at)->not->toBeNull()
        ->and($followUp->assistant_turns)->toBe(3)
        ->and($followUp->last_result_event['origin']['kind'])->toBe('task-notification')
        ->and($agents->pluck('status')->unique()->all())->toBe([ThreadAgentStatus::Completed])
        ->and($agents->pluck('follow_up_run_id')->unique()->all())->toBe([$followUp->id])
        ->and($agents->first()->total_tokens)->toBeGreaterThan(0)
        ->and($thread->refresh()->lock_turn_id)->toBe((string) $followUp->id);
    Event::assertDispatched(TurnStarted::class, fn (TurnStarted $event): bool => $event->run->is($followUp));

    // Both agents are done and nothing else runs, so the reply is announced when it ends.
    $this->travel(60)->seconds();
    app(ThreadDriver::class)->tick();

    expect($followUp->refresh()->turn_state)->toBe(TurnState::Completed)
        ->and(ThreadAgents::isQuietTurn($followUp))->toBeFalse()
        ->and(ThreadRequestStatus::for($thread)['key'])->toBe('done');
    Event::assertDispatchedTimes(TurnCompleted::class, 2);
});

it('treats a reply to one agent as quiet while another still runs', function () {
    [$thread] = agentFixtureTurn();
    $followUp = TaskRun::factory()->create(['thread_id' => $thread->id, 'kind' => TaskRunKind::AgentFollowUp, 'turn_state' => TurnState::Completed]);
    $running = ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    expect(ThreadAgents::isQuietTurn($followUp))->toBeTrue();

    $running->update(['status' => ThreadAgentStatus::Completed, 'finished_at' => now()]);
    expect(ThreadAgents::isQuietTurn($followUp))->toBeFalse();

    $running->update(['status' => ThreadAgentStatus::Running, 'finished_at' => null]);
    $followUp->update(['turn_state' => TurnState::Failed]);
    expect(ThreadAgents::isQuietTurn($followUp))->toBeFalse();
});

it('publishes started, progress and finished events with progress kept out of the store', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, BACKGROUND_FIXTURE);

    $stored = ThreadEvent::query()->where('thread_id', $thread->id)->whereIn('type', ['agent.started', 'agent.progress', 'agent.finished'])->orderBy('sequence')->get();

    expect($stored->pluck('type')->unique()->values()->all())->toBe(['agent.started', 'agent.finished'])
        ->and($stored->where('type', 'agent.started')->first()->payload)->toMatchArray(['id' => 'a657fe1a0f4bd68b8', 'kind' => 'agent', 'status' => 'running', 'depth' => 1, 'description' => 'Background sleep A'])
        ->and($stored->where('type', 'agent.finished')->first()->payload)->toMatchArray(['id' => 'a657fe1a0f4bd68b8', 'status' => 'completed']);
});

it('ignores task lines of commands that are not agents', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, BACKGROUND_FIXTURE);

    expect(ThreadAgent::query()->pluck('task_id')->all())->toBe(['a657fe1a0f4bd68b8', 'a8561f7f6c67ff93f']);
});

it('records a workflow with its phases', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, 'workflow-background');

    $workflow = ThreadAgent::query()->where('thread_id', $thread->id)->sole();

    expect($workflow->kind)->toBe(ThreadAgentKind::Workflow)
        ->and($workflow->workflow_name)->toBe('tiny')
        ->and($workflow->is_background)->toBeTrue()
        ->and($workflow->status)->toBe(ThreadAgentStatus::Completed)
        ->and($workflow->workflow_progress)->toBeArray()->not->toBeEmpty();
});

it('nests an agent under the agent that started it and tags their steps', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, 'nested-agents-forwarded');

    $parent = ThreadAgent::query()->where('depth', 1)->sole();
    $child = ThreadAgent::query()->where('depth', 2)->sole();

    expect($child->parent_task_id)->toBe($parent->task_id)
        ->and($child->is_background)->toBeFalse();

    $entries = ThreadEvent::query()->where('thread_id', $thread->id)->where('type', 'entry')->get();
    $tagged = $entries->filter(fn (ThreadEvent $event): bool => isset($event->payload['agentId']));

    expect($tagged->pluck('payload.agentId')->unique()->sort()->values()->all())->toBe(collect([$parent->task_id, $child->task_id])->sort()->values()->all())
        ->and($entries->reject(fn (ThreadEvent $event): bool => isset($event->payload['agentId']))->pluck('payload.role')->contains('result'))->toBeTrue();
});

it('marks a stopped agent stopped and lets its child finish on its own', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, 'agent-parent-stopped');

    $parent = ThreadAgent::query()->where('depth', 1)->sole();
    $child = ThreadAgent::query()->where('depth', 2)->sole();

    expect($parent->status)->toBe(ThreadAgentStatus::Stopped)
        ->and($child->status)->toBe(ThreadAgentStatus::Completed)
        ->and($child->finished_at->gt($parent->finished_at) || $child->finished_at->eq($parent->finished_at))->toBeTrue();
});

it('records the status of a single stopped agent', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, 'agent-stopped');

    expect(ThreadAgent::query()->where('status', ThreadAgentStatus::Stopped)->count())->toBe(1);
});

it('keeps the model summary of an agent from its progress lines', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, 'agent-progress-summaries');

    expect(ThreadAgent::query()->whereNotNull('summary')->exists())->toBeTrue()
        ->and(ThreadAgent::query()->where('tool_uses', '>', 0)->exists())->toBeTrue();
});

it('does not count an agent line as a step of the turn', function () {
    [$thread, $run] = agentFixtureTurn();

    playAgentFixture($thread, 'nested-agents-forwarded');

    $mainSteps = collect(agentFixtureLines('nested-agents-forwarded'))
        ->filter(fn (array $line): bool => ($line['type'] ?? null) === 'assistant' && ! isset($line['parent_tool_use_id']) && ! isset($line['agent_id']))->count();

    expect($run->refresh()->assistant_turns)->toBe($mainSteps);
});

it('applies a fixture twice without a second agent row or event', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, BACKGROUND_FIXTURE);
    $events = ThreadEvent::query()->count();
    playAgentFixture($thread, BACKGROUND_FIXTURE);

    expect(ThreadAgent::query()->count())->toBe(2)
        ->and(ThreadEvent::query()->count())->toBe($events);
});

it('stores the prompt Claude gave an agent', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, 'agent-stopped');

    expect(ThreadAgent::query()->first()->prompt)->toStartWith('Run `for i in $(seq 20)')
        ->and(ThreadAgentsData::row(ThreadAgent::query()->first())['prompt'])->toStartWith('Run `for i');
});

it('keeps what an agent works on from its own tool calls', function () {
    [$thread] = agentFixtureTurn();

    playAgentFixture($thread, 'agent-progress-summaries');

    $target = ThreadAgent::query()->whereNotNull('last_tool_target')->first();
    expect($target)->not->toBeNull()
        ->and($target->last_tool_target)->toStartWith('for i in $(seq 7)')
        ->and(mb_strlen($target->last_tool_target))->toBeLessThanOrEqual(80);
});

it('derives a short target from a tool input', function (array $input, ?string $expected) {
    expect(ThreadAgents::targetOf($input))->toBe($expected);
})->with([
    'file' => [['file_path' => '/w/crm.html', 'content' => 'x'], '/w/crm.html'],
    'command' => [['command' => str_repeat('a', 100)], str_repeat('a', 80)],
    'pattern' => [['pattern' => '*.php'], '*.php'],
    'url' => [['url' => 'https://x.test'], 'https://x.test'],
    'agent' => [['description' => 'Explore', 'prompt' => 'p'], 'Explore'],
    'none' => [['foo' => 'bar'], null],
]);
