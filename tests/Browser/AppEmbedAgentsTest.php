<?php

use App\Enums\RunnerState;
use App\Enums\ThreadAgentStatus;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Models\ThreadEvent;
use App\Models\YakTask;

/**
 * A thread of the embed user with one finished run whose reply called the agent, and the agent's own steps.
 *
 * @return array{0: Thread, 1: array<string, string>}
 */
function embedAgentThread(ThreadAgent $agent): array
{
    [$user, , $headers] = embedPhone();
    $thread = Thread::factory()->private($user)->create(['runner_state' => RunnerState::Idle]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'repo' => $thread->repo, 'pr_url' => null, 'started_at' => now()]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed', 'started_at' => now()->subMinutes(10), 'num_turns' => 3, 'total_ms' => 60_000]);
    $agent->forceFill(['thread_id' => $thread->id])->save();
    $sequence = 1;
    $event = function (string $type, array $payload) use ($thread, &$sequence): void {
        ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => $sequence++, 'type' => $type, 'payload' => $payload]);
    };

    $event('turn', ['turnId' => $run->id, 'state' => 'running']);
    $event('entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [['type' => 'tool_use', 'id' => $agent->tool_use_id, 'name' => 'Agent', 'input' => ['description' => $agent->description]]]]);
    $event('entry', ['role' => 'user', 'agentId' => $agent->task_id, 'kind' => 'prompt', 'text' => 'Build the company list page.']);
    $event('entry', ['role' => 'assistant', 'messageId' => 'a1', 'agentId' => $agent->task_id, 'blocks' => [['type' => 'tool_use', 'id' => 'toolu_step1', 'name' => 'Bash', 'input' => ['command' => 'echo agent-step-one']]]]);
    $event('entry', ['role' => 'tool_result', 'agentId' => $agent->task_id, 'toolUseId' => 'toolu_step1', 'isError' => false, 'text' => 'agent-step-one']);
    $event('turn', ['turnId' => $run->id, 'state' => 'completed']);
    $thread->forceFill(['event_sequence' => $sequence - 1])->save();

    return [$thread, $headers];
}

/** The `agents` messages the page has posted so far, as JSON. */
function postedAgents(mixed $page, string $ready): mixed
{
    return settled($page, $ready, "JSON.stringify((window.__yakEmbedLog ?? []).filter((message) => message.type === 'agents').map(({ running, newest }) => ({ running, newest })))");
}

it('expands an agent card in the embed without a Stop button and posts agents when one starts', function () {
    $agent = ThreadAgent::factory()->finished(ThreadAgentStatus::Completed)->create(['thread_id' => Thread::factory(), 'task_id' => 'aone', 'tool_use_id' => 'toolu_agent', 'description' => 'Build company mockups', 'summary' => 'Company pages are done.']);
    [$thread, $headers] = embedAgentThread($agent);

    $page = visitEmbedThread($thread, $headers);
    waitUntilPresent($page, '[data-testid="agent-card"]');
    $page->assertAttribute('[data-testid="agent-card"]', 'id', 'agent-aone')
        ->assertMissing('[data-testid="agent-stop"]')
        ->assertMissing('[data-testid="agent-card-body"]')
        ->click('[data-testid="agent-card-toggle"]');
    waitUntilPresent($page, '[data-testid="agent-card-body"]');
    $page->assertSeeIn('[data-testid="agent-card-summary"]', 'Company pages are done.')
        ->assertSeeIn('[data-testid="agent-card-body"]', 'echo agent-step-one')
        ->assertMissing('[data-testid="agent-stop"]');

    // Nothing runs, and the page says so once on mount. A start shows after the page reads its agents again.
    $some = fn (int $count) => "(window.__yakEmbedLog ?? []).filter((message) => message.type === 'agents').length >= {$count}";
    expect(json_decode((string) postedAgents($page, $some(1)), true))->toBe([['running' => 0, 'newest' => null]]);
    $agent->forceFill(['status' => ThreadAgentStatus::Running, 'finished_at' => null])->save();
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'refresh' }); true");

    expect(json_decode((string) postedAgents($page, $some(2)), true))->toBe([['running' => 0, 'newest' => null], ['running' => 1, 'newest' => 'agent-aone']]);
    waitUntilPresent($page, '[data-testid="agent-card"][data-status="running"]');
    $page->assertMissing('[data-testid="agent-stop"]')->assertNoJavaScriptErrors();
});

it('scrolls to the card an agents message named when the app sends focus', function () {
    $agent = ThreadAgent::factory()->create(['thread_id' => Thread::factory(), 'task_id' => 'aone', 'tool_use_id' => 'toolu_agent', 'description' => 'Build company mockups']);
    [$thread, $headers] = embedAgentThread($agent);

    $page = visitEmbedThread($thread, $headers);
    waitUntilPresent($page, '[data-testid="agent-card"]');
    $newest = json_decode((string) postedAgents($page, "(window.__yakEmbedLog ?? []).some((message) => message.type === 'agents')"), true)[0]['newest'];
    $page->script("window.__yakEmbed.receive({ v: 1, type: 'focus', fragment: '{$newest}' }); true");

    expect($newest)->toBe('agent-aone')
        ->and(settled($page, "document.getElementById('agent-aone')?.hasAttribute('data-fragment-target')", 'true'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
