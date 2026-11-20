<?php

use App\Enums\TurnState;
use App\Http\Resources\ThreadAgentsData;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

function agentLogEntry(Thread $thread, int $sequence, array $payload): ThreadEvent
{
    return ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence, 'type' => 'entry', 'payload' => $payload]);
}

function threadWithAgentSteps(): array
{
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->running()->create(['thread_id' => $thread->id]);
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'yak_task_id' => $task->id]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 1, 'type' => 'turn', 'payload' => ['turnId' => $run->id, 'state' => 'running']]);
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone', 'tool_use_id' => 'toolu_agent']);
    $other = ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'atwo']);

    agentLogEntry($thread, 2, ['role' => 'assistant', 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_main', 'name' => 'Agent', 'input' => ['prompt' => 'go']]]]);
    agentLogEntry($thread, 3, ['role' => 'assistant', 'agentId' => 'aone', 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_a1', 'name' => 'Bash', 'input' => ['command' => 'ls']]]]);
    agentLogEntry($thread, 4, ['role' => 'tool_result', 'agentId' => 'aone', 'toolUseId' => 'toolu_a1', 'isError' => false, 'text' => 'file.txt']);
    agentLogEntry($thread, 5, ['role' => 'assistant', 'agentId' => 'atwo', 'blocks' => [['type' => 'text', 'text' => 'Other agent']]]);
    agentLogEntry($thread, 6, ['role' => 'assistant', 'agentId' => 'toolu_agent', 'blocks' => [['type' => 'text', 'text' => 'Found by tool use id']]]);
    agentLogEntry($thread, 7, ['role' => 'assistant', 'blocks' => [['type' => 'text', 'text' => 'Back in Claude']]]);

    return [$thread, $run, $agent, $other];
}

it('reads one agent log with its own steps and tool output only', function () {
    [$thread] = threadWithAgentSteps();

    $response = $this->actingAs(User::factory()->create())->getJson(route('threads.agents.log', [$thread, 'aone']))->assertOk();

    expect($response->json('nextCursor'))->toBeNull()
        ->and(array_column($response->json('entries'), 'id'))->toBe([3, 6])
        ->and($response->json('entries.0.kind'))->toBe('tool')
        ->and($response->json('entries.0.input'))->toBe('ls')
        ->and($response->json('entries.0.output'))->toBe('file.txt')
        ->and($response->json('entries.0.number'))->toBe(1)
        ->and($response->json('entries.1.number'))->toBe(2);
});

it('pages an agent log 50 steps at a time by cursor', function () {
    $thread = Thread::factory()->create();
    ThreadAgent::factory()->create(['thread_id' => $thread->id, 'task_id' => 'aone']);

    foreach (range(2, 61) as $sequence) {
        agentLogEntry($thread, $sequence, ['role' => 'assistant', 'agentId' => 'aone', 'blocks' => [['type' => 'text', 'text' => "Step {$sequence}"]]]);
    }

    $user = User::factory()->create();
    $first = $this->actingAs($user)->getJson(route('threads.agents.log', [$thread, 'aone']))->assertOk();
    $second = $this->actingAs($user)->getJson(route('threads.agents.log', [$thread, 'aone']) . '?cursor=' . $first->json('nextCursor'))->assertOk();

    expect($first->json('entries'))->toHaveCount(50)
        ->and($first->json('nextCursor'))->toBe(51)
        ->and($second->json('entries'))->toHaveCount(10)
        ->and($second->json('entries.0.number'))->toBe(51)
        ->and($second->json('nextCursor'))->toBeNull();
});

it('leaves agent steps out of the run log of Claude', function () {
    [$thread, $run] = threadWithAgentSteps();

    $response = $this->actingAs(User::factory()->create())->getJson("/threads/{$thread->public_id}/runs/{$run->id}/log")->assertOk();

    expect(array_column($response->json('entries'), 'id'))->toBe([2, 7]);
});

it('counts the agents in the page props of the thread', function () {
    [$thread, $run, $agent] = threadWithAgentSteps();
    $run->update(['turn_state' => TurnState::Completed]);
    $agent->messages()->create(['text' => 'Check the config', 'user_id' => $user = User::factory()->create()->id]);
    ThreadAgent::query()->where('task_id', 'atwo')->update(['status' => 'completed', 'finished_at' => now()]);

    $this->actingAs($thread->owner ?? User::factory()->create())->get(route('threads.show', $thread))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('agents.runningCount', 1)
            ->where('agents.main.state', 'waiting')
            ->where('agents.main.runningAgents', 1)
            ->has('agents.agents', 2)
            ->where('agents.agents.0.id', 'aone')
            ->where('agents.agents.0.messages.0.text', 'Check the config')
            ->where('agents.agents.0.messages.0.forwardedAt', null)
            ->where('agents.agents.1.status', 'completed'));
});

it('lists the sub-threads of the thread beside its agents without a query per child', function () {
    $thread = Thread::factory()->create();
    $children = Thread::factory()->count(3)->create(['parent_thread_id' => $thread->id, 'root_thread_id' => $thread->id]);
    ThreadAgent::factory()->create(['thread_id' => $thread->id]);

    DB::enableQueryLog();
    $data = ThreadAgentsData::for($thread);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($data['subThreads'])->toHaveCount(3)
        ->and(array_column($data['subThreads'], 'id'))->toBe($children->pluck('public_id')->all())
        ->and($data['subThreads'][0])->toHaveKeys(['id', 'name', 'title', 'kind', 'state', 'step', 'branch', 'pullRequestUrl'])
        ->and($queries)->toBeLessThanOrEqual(10);
});

it('shapes an agent row and the main agent state', function () {
    $thread = Thread::factory()->create();
    $agent = ThreadAgent::factory()->create(['thread_id' => $thread->id, 'total_tokens' => 1234, 'tool_uses' => 3, 'last_tool' => 'Bash']);

    $data = ThreadAgentsData::for($thread);

    expect($data['main'])->toBe(['state' => 'waiting', 'stepsThisTurn' => null, 'runningAgents' => 1])
        ->and($data['agents'][0])->toMatchArray(['id' => $agent->task_id, 'kind' => 'agent', 'status' => 'running', 'totalTokens' => 1234, 'toolUses' => 3, 'lastTool' => 'Bash', 'depth' => 1, 'messages' => []])
        ->and(ThreadAgentsData::for(Thread::factory()->create())['main']['state'])->toBe('idle');
});
