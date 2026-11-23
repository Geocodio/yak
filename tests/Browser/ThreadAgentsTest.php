<?php

use App\DataTransferObjects\ThreadMessageResult;
use App\Enums\RunnerState;
use App\Enums\ThreadAgentStatus;
use App\Exceptions\ThreadActionRefused;
use App\Http\Resources\ThreadAgentsData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadMessageSender;

require_once __DIR__ . '/../Feature/Threads/Realtime/RealtimeRedis.php';

/**
 * A private thread with one finished run whose reply starts with the Agent call that started `$agent`, and the
 * agent's own steps after it. Events take the sequences from 1.
 *
 * @return array{0: Thread, 1: TaskRun, 2: int}
 */
function agentThread(User $owner, ?ThreadAgent $agent = null, string $toolName = 'Agent'): array
{
    $thread = Thread::factory()->private($owner)->create(['runner_state' => RunnerState::Idle]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, 'pr_url' => null, 'started_at' => now()]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed', 'started_at' => now()->subMinutes(10), 'num_turns' => 3, 'total_ms' => 60_000]);
    $sequence = 1;
    $event = function (string $type, array $payload) use ($thread, &$sequence): void {
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence++, 'type' => $type, 'payload' => $payload]);
    };

    $event('turn', ['turnId' => $run->id, 'state' => 'running']);
    $event('entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [['type' => 'text', 'text' => 'I will split this across agents.']]]);

    if ($agent !== null) {
        $event('entry', ['role' => 'assistant', 'messageId' => 'm2', 'blocks' => [['type' => 'tool_use', 'id' => $agent->tool_use_id, 'name' => $toolName, 'input' => ['description' => $agent->description ?? $agent->workflow_name]]]]);
        $event('entry', ['role' => 'user', 'agentId' => $agent->task_id, 'kind' => 'prompt', 'text' => 'Build the company list page.']);
        $event('entry', ['role' => 'assistant', 'messageId' => 'a1', 'agentId' => $agent->task_id, 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_step1', 'name' => 'Bash', 'input' => ['command' => 'echo agent-step-one']]]]);
        $event('entry', ['role' => 'tool_result', 'agentId' => $agent->task_id, 'toolUseId' => 'toolu_step1', 'isError' => false, 'text' => 'agent-step-one']);
        $event('entry', ['role' => 'assistant', 'messageId' => 'a2', 'agentId' => $agent->task_id, 'blocks' => [['type' => 'text', 'text' => 'Company pages are done.']]]);
    }

    $event('turn', ['turnId' => $run->id, 'state' => 'completed']);
    $thread->forceFill(['event_sequence' => $sequence - 1])->save();

    return [$thread, $run, $sequence];
}

/** The click answers before the request does, so the database is read again for a moment. */
function waitForStop(Closure $hasStopped): void
{
    for ($attempt = 0; $attempt < 30 && ! $hasStopped(); $attempt++) {
        usleep(100_000);
    }
}

afterEach(fn () => stopThrowawayRedis());

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
});

it('draws the agent Claude called as a card that expands to its steps and opens the full log', function () {
    $agent = ThreadAgent::factory()->create(['thread_id' => Thread::factory(), 'task_id' => 'aone', 'tool_use_id' => 'toolu_agent', 'description' => 'Build company mockups', 'started_at' => now()->subMinutes(6), 'total_tokens' => 41210, 'tool_uses' => 23, 'last_tool' => 'Write', 'last_tool_target' => '/workspace/mockups/crm-pipeline.html', 'summary' => 'Company pages are done.']);
    [$thread] = agentThread($this->owner, $agent);
    $agent->forceFill(['thread_id' => $thread->id])->save();

    visit(route('threads.show', $thread))
        ->assertVisible('[data-testid="agent-card"]')
        ->assertSeeIn('[data-testid="agent-card-title"]', 'Build company mockups')
        ->assertSeeIn('[data-testid="agent-card-subtitle"]', 'general-purpose · background · Running')
        ->assertSeeIn('[data-testid="agent-card-subtitle"]', 'now: Write crm-pipeline.html')
        ->assertVisible('[data-testid="agent-stop"]')
        ->assertMissing('[data-testid="agent-card-body"]')
        ->click('[data-testid="agent-card-toggle"]')
        ->assertVisible('[data-testid="agent-card-body"]')
        ->assertSeeIn('[data-testid="agent-card-summary"]', 'Company pages are done.')
        ->assertSeeIn('[data-testid="agent-card-body"]', 'echo agent-step-one')
        ->assertSeeIn('[data-testid="agent-card-subtitle"]', '41k tokens')
        ->click('[data-testid="agent-card-full-log"]')
        ->assertVisible('[data-testid="transcript-overlay"]')
        ->assertSee('echo agent-step-one')
        ->assertNoJavaScriptErrors();
});

it('keeps an agent out of Claude\'s own reply and shows no stop button once it has finished', function () {
    $agent = ThreadAgent::factory()->finished()->create(['thread_id' => Thread::factory(), 'task_id' => 'adone', 'tool_use_id' => 'toolu_done', 'description' => 'Read the spec', 'duration_ms' => 544_000, 'total_tokens' => 22000]);
    [$thread] = agentThread($this->owner, $agent);
    $agent->forceFill(['thread_id' => $thread->id])->save();

    visit(route('threads.show', $thread))
        ->assertVisible('[data-testid="agent-card"][data-status="completed"]')
        ->assertSeeIn('[data-testid="agent-card-subtitle"]', 'Finished after 9m 04s')
        ->assertMissing('[data-testid="agent-stop"]')
        ->assertDontSee('agent-step-one')
        ->assertDontSee('Company pages are done.')
        ->assertNoJavaScriptErrors();
});

it('draws a workflow as a card with its phases', function () {
    $workflow = ThreadAgent::factory()->workflow('screenshot-review')->create([
        'thread_id' => Thread::factory(),
        'tool_use_id' => 'toolu_flow',
        'description' => null,
        'workflow_progress' => [
            ['type' => 'workflow_phase', 'index' => 1, 'title' => 'Capture'],
            ['type' => 'workflow_phase', 'index' => 2, 'title' => 'Review'],
            ['type' => 'workflow_phase', 'index' => 3, 'title' => 'Report'],
            ['type' => 'workflow_agent', 'phaseIndex' => 1, 'state' => 'done'],
            ['type' => 'workflow_agent', 'phaseIndex' => 2, 'state' => 'done'],
            ['type' => 'workflow_agent', 'phaseIndex' => 2, 'state' => 'start'],
        ],
    ]);
    [$thread] = agentThread($this->owner, $workflow, 'Workflow');
    $workflow->forceFill(['thread_id' => $thread->id])->save();

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="agent-card-title"]', 'Workflow: screenshot-review')
        ->assertSeeIn('[data-testid="agent-card-subtitle"]', '3 agents')
        ->assertSeeIn('[data-testid="workflow-phases"]', 'Capture · done')
        ->assertSeeIn('[data-testid="workflow-phases"]', 'Review · 1 of 2 agents')
        ->assertSeeIn('[data-testid="workflow-phases"]', 'Report · waiting')
        ->assertNoJavaScriptErrors();
});

it('shows an agent that only the live feed knows, and its progress', function () {
    [$thread] = agentThread($this->owner);
    $live = ThreadAgent::factory()->make(['thread_id' => $thread->id, 'task_id' => 'alive', 'tool_use_id' => 'toolu_live', 'description' => 'Live only agent', 'started_at' => now(), 'last_tool' => 'Grep']);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 100, 'type' => 'agent.started', 'payload' => ThreadAgentsData::row($live)]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 101, 'type' => 'agent.finished', 'payload' => ThreadAgentsData::row($live->forceFill(['status' => 'completed', 'finished_at' => now(), 'duration_ms' => 62_000]))]);
    $thread->forceFill(['event_sequence' => 101])->save();

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-agents"]')
        ->assertSeeIn('[data-testid="agent-row"]', 'Live only agent')
        ->assertAttribute('[data-testid="agent-row"]', 'data-status', 'completed')
        ->assertNoJavaScriptErrors();
});

it('says "<agent> finished after <duration>. Claude continued." before the reply it started', function () {
    $agent = ThreadAgent::factory()->finished()->create(['thread_id' => Thread::factory(), 'task_id' => 'adone', 'tool_use_id' => 'toolu_done', 'description' => 'Build company mockups', 'duration_ms' => 544_000]);
    [$thread, $firstRun, $sequence] = agentThread($this->owner, $agent);
    $agent->forceFill(['thread_id' => $thread->id])->save();
    $second = TaskRun::factory()->create(['yak_task_id' => $firstRun->yak_task_id, 'thread_id' => $thread->id, 'kind' => 'agent_follow_up', 'turn_state' => 'completed', 'started_at' => now()->subMinutes(1), 'num_turns' => 1, 'total_ms' => 5000]);
    $agent->forceFill(['follow_up_run_id' => $second->id])->save();
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'turn', 'payload' => ['turnId' => $second->id, 'state' => 'running']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence + 1, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'messageId' => 'm9', 'blocks' => [['type' => 'text', 'text' => 'Six of the twelve mockups are in.']]]]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence + 2, 'type' => 'turn', 'payload' => ['turnId' => $second->id, 'state' => 'completed']]);
    $thread->forceFill(['event_sequence' => $sequence + 2])->save();

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="agent-continued-row"]', 'Build company mockups finished after 9m 04s. Claude continued.')
        ->assertSee('Six of the twelve mockups are in.')
        ->assertNoJavaScriptErrors();
});

it('names every agent a merged follow-up run took in, in finish order', function () {
    $first = ThreadAgent::factory()->finished()->create(['thread_id' => Thread::factory(), 'task_id' => 'afirst', 'tool_use_id' => 'toolu_first', 'description' => 'Build company mockups', 'finished_at' => now()->subSeconds(20)]);
    [$thread, $firstRun, $sequence] = agentThread($this->owner, $first);
    $first->forceFill(['thread_id' => $thread->id])->save();
    $second = ThreadAgent::factory()->finished()->create(['thread_id' => $thread->id, 'task_id' => 'asecond', 'tool_use_id' => 'toolu_second', 'description' => 'Review the mockups', 'finished_at' => now()->subSeconds(10)]);
    $run = TaskRun::factory()->create(['yak_task_id' => $firstRun->yak_task_id, 'thread_id' => $thread->id, 'kind' => 'agent_follow_up', 'turn_state' => 'completed', 'started_at' => now()->subMinutes(1), 'num_turns' => 1, 'total_ms' => 5000]);
    ThreadAgent::query()->whereKey([$first->id, $second->id])->update(['follow_up_run_id' => $run->id]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence + 1, 'type' => 'entry', 'payload' => ['role' => 'assistant', 'messageId' => 'm9', 'blocks' => [['type' => 'text', 'text' => 'Both are in.']]]]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence + 2, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'completed']]);
    $thread->forceFill(['event_sequence' => $sequence + 2])->save();

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="agent-continued-row"]', 'Build company mockups and Review the mockups finished. Claude continued.')
        ->assertScript('document.querySelectorAll(\'[data-testid="agent-continued-row"]\').length', 1)
        ->assertNoJavaScriptErrors();
});

it('shows the running count above the composer, opens the panel and stops everything after an inline confirm', function () {
    useThrowawayRedis();
    $thread = Thread::factory()->private($this->owner)->create(['runner_state' => RunnerState::Idle]);
    ThreadAgent::factory()->count(2)->create(['thread_id' => $thread->id]);
    ThreadAgent::factory()->workflow()->create(['thread_id' => $thread->id]);
    ThreadAgent::factory()->finished()->create(['thread_id' => $thread->id]);

    visit(route('threads.show', $thread))
        ->assertSeeIn('[data-testid="agents-bar-summary"]', '2 agents and 1 workflow running')
        ->click('[data-testid="agents-stop-all"]')
        ->assertVisible('[data-testid="agents-stop-all-confirm"]')
        ->assertSeeIn('[data-testid="agents-stop-all-confirm"]', 'Stop all 3?')
        ->click('[data-testid="agents-stop-all-cancel"]')
        ->assertMissing('[data-testid="agents-stop-all-confirm"]')
        ->assertVisible('[data-testid="agents-stop-all"]');

    expect(ThreadAgent::query()->whereNotNull('stop_requested_at')->count())->toBe(0);

    visit(route('threads.show', $thread))
        ->click('[data-testid="agents-bar-view"]')
        ->assertVisible('[data-testid="agents-panel"]')
        ->click('[data-testid="agents-bar"] [data-testid="agents-stop-all"]')
        ->click('[data-testid="agents-stop-all-confirmed"]')
        ->assertMissing('[data-testid="agents-stop-all-confirm"]')
        ->assertNoJavaScriptErrors();

    waitForStop(fn (): bool => ThreadAgent::query()->whereNotNull('stop_requested_at')->count() === 3);
    expect(ThreadAgent::query()->whereNotNull('stop_requested_at')->count())->toBe(3);
});

it('lists Claude, the nested agents and the sub-threads in the Agents panel', function () {
    $thread = Thread::factory()->private($this->owner)->create(['runner_state' => RunnerState::Idle]);
    $parent = ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aparent', 'description' => 'Build company mockups', 'total_tokens' => 41210, 'tool_uses' => 23, 'started_at' => now()->subMinutes(6)]);
    ThreadAgent::factory()->childOf($parent)->create(['task_id' => 'achild', 'description' => 'Check Atlas tokens']);
    ThreadAgent::factory()->finished()->create(['thread_id' => $thread->id, 'task_id' => 'adone', 'description' => 'Read the spec', 'duration_ms' => 100_000, 'total_tokens' => 22000]);
    ThreadAgent::factory()->finished(ThreadAgentStatus::Stopped)->create(['thread_id' => $thread->id, 'task_id' => 'astopped', 'description' => 'First pass at settings', 'duration_ms' => 260_000]);
    Thread::factory()->child($thread)->create(['title' => 'Atlas design tokens', 'branch' => 'atlas-tokens']);

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-agents"]')
        ->assertSeeIn('[data-testid="agents-main-row"]', 'Claude (this thread)')
        ->assertSeeIn('[data-testid="agents-list"]', 'A helper Claude runs inside this thread. Same branch, same changes.')
        ->assertSeeIn('[data-testid="agents-list"]', 'A separate thread with its own branch and PR. Open one to talk to it directly.')
        ->assertSeeIn('[data-testid="agent-row"][data-agent-id="achild"]', 'started by Build company mockups')
        ->assertSeeIn('[data-testid="agent-row"][data-agent-id="adone"]', 'finished')
        ->assertSeeIn('[data-testid="agent-row"][data-agent-id="astopped"]', 'stopped')
        ->assertSeeIn('[data-testid="agents-subthread-row"]', 'Atlas design tokens')
        ->assertSeeIn('[data-testid="agents-subthread-row"]', 'branch atlas-tokens')
        ->assertNoJavaScriptErrors();
});

it('shows the selected agent with its stats, task and log, and stops it', function () {
    useThrowawayRedis();
    $agent = ThreadAgent::factory()->create(['thread_id' => Thread::factory(), 'task_id' => 'aone', 'tool_use_id' => 'toolu_agent', 'description' => 'Build company mockups', 'started_at' => now()->subMinutes(6), 'total_tokens' => 41210, 'tool_uses' => 23, 'model' => 'claude-sonnet-5-5', 'prompt' => 'Build the company list page.']);
    [$thread] = agentThread($this->owner, $agent);
    $agent->forceFill(['thread_id' => $thread->id])->save();

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-agents"]')
        ->click('[data-testid="agent-row"][data-agent-id="aone"]')
        ->assertSeeIn('[data-testid="agent-detail-title"]', 'Build company mockups')
        ->assertSeeIn('[data-testid="agent-detail"]', '41,210')
        ->assertSeeIn('[data-testid="agent-detail-task"]', 'Build the company list page.')
        ->assertSeeIn('[data-testid="agent-detail"]', 'echo agent-step-one')
        ->assertSeeIn('[data-testid="agent-detail"]', 'Company pages are done.')
        ->click('[data-testid="agent-detail"] [data-testid="agent-stop"]')
        ->assertSeeIn('[data-testid="agent-detail"] [data-testid="agent-stop"]', 'Stopping')
        ->assertNoJavaScriptErrors();

    waitForStop(fn (): bool => $agent->refresh()->stop_requested_at !== null);

    expect($agent->refresh()->stop_requested_at)->not->toBeNull();
});

it('sends a message to an agent and says Claude has not passed it on yet', function () {
    $this->mock(ThreadMessageSender::class)->shouldReceive('send')->once()->andReturn(new ThreadMessageResult('steered', 'u-1', null));
    $thread = Thread::factory()->private($this->owner)->create(['runner_state' => RunnerState::Idle]);
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone', 'description' => 'Build company mockups']);

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-agents"]')
        ->click('[data-testid="agent-row"][data-agent-id="aone"]')
        ->fill('[data-testid="agent-message-input"]', 'Use the compact table style')
        ->click('[data-testid="agent-message-send"]')
        ->assertSeeIn('[data-testid="agent-message"]', 'Use the compact table style')
        ->assertSeeIn('[data-testid="agent-message"]', 'Waiting for Claude to pass it on')
        ->assertValue('[data-testid="agent-message-input"]', '')
        ->assertNoJavaScriptErrors();
});

it('shows why a message to an agent was not sent and keeps what was typed', function () {
    $this->mock(ThreadMessageSender::class)->shouldReceive('send')->once()->andThrow(ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.'));
    $thread = Thread::factory()->private($this->owner)->create(['runner_state' => RunnerState::Idle]);
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone']);

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-agents"]')
        ->click('[data-testid="agent-row"][data-agent-id="aone"]')
        ->fill('[data-testid="agent-message-input"]', 'Hello there')
        ->click('[data-testid="agent-message-send"]')
        ->assertSeeIn('[data-testid="agent-message-error"]', 'This thread is closed.')
        ->assertValue('[data-testid="agent-message-input"]', 'Hello there')
        ->assertMissing('[data-testid="agent-message"]')
        ->assertNoJavaScriptErrors();
});

it('says Claude passed a message on once it has', function () {
    $thread = Thread::factory()->private($this->owner)->create(['runner_state' => RunnerState::Idle]);
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone']);
    $agent->messages()->create(['user_id' => $this->owner->id, 'text' => 'Check the config', 'forwarded_at' => now()]);

    visit(route('threads.show', $thread))
        ->click('[data-testid="thread-dock-toggle-agents"]')
        ->click('[data-testid="agent-row"][data-agent-id="aone"]')
        ->assertSeeIn('[data-testid="agent-message-passed"]', 'Claude passed it on')
        ->assertNoJavaScriptErrors();
});

it('tells a thread without agents that Claude\'s helpers will show here', function () {
    $thread = Thread::factory()->private($this->owner)->create(['runner_state' => RunnerState::Idle]);

    visit(route('threads.show', $thread))
        ->assertMissing('[data-testid="agents-bar"]')
        ->click('[data-testid="thread-dock-toggle-agents"]')
        ->assertSeeIn('[data-testid="agents-empty"]', 'No agents yet. When Claude starts helpers, they show here.')
        ->assertNoJavaScriptErrors();
});

it('works on a phone: the Agents tab lists agents and a tap opens one with a way back', function () {
    $agent = ThreadAgent::factory()->create(['thread_id' => Thread::factory(), 'task_id' => 'aone', 'tool_use_id' => 'toolu_agent', 'description' => 'Build company mockups', 'started_at' => now()->subMinutes(2)]);
    [$thread] = agentThread($this->owner, $agent);
    $agent->forceFill(['thread_id' => $thread->id])->save();

    visit(route('threads.show', $thread))->on()->mobile()
        ->assertSeeIn('[data-testid="agents-bar-summary"]', '1 agent running')
        ->wait(1)
        ->click('[data-testid="agents-bar-view"]')
        ->assertVisible('[data-testid="agents-panel"]')
        ->assertVisible('[data-testid="agent-row"]')
        ->assertMissing('[data-testid="agent-detail"]')
        ->click('[data-testid="agent-row"]')
        ->assertVisible('[data-testid="agent-detail"]')
        ->assertMissing('[data-testid="agents-list"]:visible')
        ->click('[data-testid="agent-detail-back"]')
        ->assertVisible('[data-testid="agent-row"]')
        ->assertNoJavaScriptErrors();
});
