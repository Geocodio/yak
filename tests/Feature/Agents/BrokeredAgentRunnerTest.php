<?php

use App\Agents\BrokeredAgentRunner;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskMode;
use App\Exceptions\ClaudeAuthException;
use App\Jobs\RunYakReviewJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    $this->runner = new BrokeredAgentRunner($this->streams, app(AuthProxyPlaceholders::class), exitWaitSeconds: 0);
    $this->task = YakTask::factory()->running()->create();
    $this->account = ClaudeAccount::factory()->system()->create();
});

function oneshotRequest(object $test, int $timeoutSeconds = 5): AgentRunRequest
{
    return new AgentRunRequest(
        prompt: 'Review this pull request',
        systemPrompt: 'You are Yak',
        containerName: 'yak-review-1',
        timeoutSeconds: $timeoutSeconds,
        maxTurns: 10,
        model: 'sonnet',
        task: $test->task,
        account: $test->account,
    );
}

/** Pushes the lines of a finished turn onto the channel the runner registers. */
function finishTurnOn(FakeThreadStreams $streams, string $channel, string $summary = 'All good', float $cost = 0.42): void
{
    $uuid = $streams->commandsFor($channel)[1]['uuid'];
    $streams->pushOutbound($channel, ['type' => 'command_lifecycle', 'command_uuid' => $uuid, 'state' => 'started']);
    $streams->pushOutbound($channel, ['type' => 'system', 'subtype' => 'init', 'session_id' => 'session-1']);
    $streams->pushOutbound($channel, ['type' => 'assistant', 'session_id' => 'session-1', 'message' => ['content' => [['type' => 'text', 'text' => $summary]]]]);
    $streams->pushOutbound($channel, ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 'session-1', 'result' => $summary, 'total_cost_usd' => $cost]);
    $streams->pushOutbound($channel, ['type' => 'command_lifecycle', 'command_uuid' => $uuid, 'state' => 'completed']);
}

it('runs one turn and ends the runner', function () {
    $this->streams->onCommand = fn (string $channel, array $command) => $command['type'] === 'turn' ? finishTurnOn($this->streams, $channel) : null;

    $this->runner->run(oneshotRequest($this));

    $channel = $this->streams->registeredEver[0];
    $commands = $this->streams->commandsFor($channel);

    expect($channel)->toStartWith('oneshot:')
        ->and(array_column($commands, 'type'))->toBe(['start_runner', 'turn', 'end'])
        ->and($commands[0]['container'])->toBe('yak-review-1')
        ->and($commands[0]['start']['placeholder'])->toStartWith('yakph-')
        ->and($commands[0])->not->toHaveKey('credential_reference')
        ->and($commands[1]['text'])->toBe('Review this pull request');
});

it('sends the path of the mcp config instead of the servers', function () {
    $path = tempnam(sys_get_temp_dir(), 'mcp');
    file_put_contents($path, json_encode(['mcpServers' => ['linear' => ['headers' => ['Authorization' => 'Bearer super-secret']]]]));
    $this->streams->onCommand = fn (string $channel, array $command) => $command['type'] === 'turn' ? finishTurnOn($this->streams, $channel) : null;

    $this->runner->run(new AgentRunRequest(
        prompt: 'p', systemPrompt: 's', containerName: 'yak-review-1', timeoutSeconds: 5, maxTurns: 10,
        model: 'sonnet', task: $this->task, account: $this->account, mcpConfigPath: $path,
    ));

    $start = $this->streams->commandsFor($this->streams->registeredEver[0])[0]['start'];
    unlink($path);

    expect($start['mcpConfigPath'])->toBe('/home/yak/mcp-config.json')
        ->and($start)->not->toHaveKey('mcpServers')
        ->and(json_encode($start))->not->toContain('super-secret');
});

it('returns the result summary and cost', function () {
    $this->streams->onCommand = fn (string $channel, array $command) => $command['type'] === 'turn' ? finishTurnOn($this->streams, $channel, 'Looks fine', 0.42) : null;

    $result = $this->runner->run(oneshotRequest($this));

    expect($result->isError)->toBeFalse()
        ->and($result->resultSummary)->toBe('Looks fine')
        ->and($result->costUsd)->toBe(0.42)
        ->and($result->sessionId)->toBe('session-1');
});

it('times out and kills the runner', function () {
    $result = $this->runner->run(oneshotRequest($this, timeoutSeconds: 0));

    $channel = $this->streams->registeredEver[0];

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('timed out')
        ->and(array_column($this->streams->commandsFor($channel), 'type'))->toBe(['start_runner', 'turn', 'kill']);
});

it('kills a run that passes its assistant turn limit', function () {
    $this->streams->onCommand = function (string $channel, array $command) {
        if ($command['type'] === 'turn') {
            foreach (range(1, 3) as $ignored) {
                $this->streams->pushOutbound($channel, ['type' => 'assistant', 'session_id' => 's', 'message' => ['content' => [['type' => 'text', 'text' => 'working']]]]);
            }
        }
    };

    $result = $this->runner->run(new AgentRunRequest(
        prompt: 'p', systemPrompt: 's', containerName: 'yak-review-1', timeoutSeconds: 30, maxTurns: 2,
        model: 'sonnet', task: $this->task, account: $this->account,
    ));

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('2 assistant turns')
        ->and(array_column($this->streams->commandsFor($this->streams->registeredEver[0]), 'type'))->toBe(['start_runner', 'turn', 'kill']);
});

it('stops at the thread wall-clock limit even when the request allows longer', function () {
    config()->set('yak.threads.turn_wall_clock_minutes', 0);

    $result = $this->runner->run(oneshotRequest($this, timeoutSeconds: 3600));

    expect($result->resultSummary)->toContain('timed out after 0 seconds');
});

it('reports a runner that failed to start as a start failure', function () {
    $this->streams->onCommand = fn (string $channel, array $command) => $command['type'] === 'start_runner'
        ? $this->streams->pushOutbound($channel, ['event' => 'runner_exited', 'exit_code' => -1], fromBroker: true)
        : null;

    $result = $this->runner->run(oneshotRequest($this));

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('failed to start')
        ->and($result->resultSummary)->not->toContain('timed out');
});

it('unregisters the channel', function () {
    $this->streams->onCommand = fn (string $channel, array $command) => $command['type'] === 'turn' ? finishTurnOn($this->streams, $channel) : null;

    $this->runner->run(oneshotRequest($this));

    expect($this->streams->channels('oneshot:'))->toBe([])
        ->and($this->streams->registeredEver)->toHaveCount(1);
});

it('refuses to run without an account', function () {
    $request = oneshotRequest($this);

    $this->runner->run(new AgentRunRequest(...[...get_object_vars($request), 'account' => null]));
})->throws(ClaudeAuthException::class);

it('hands review requests the repository unattended account', function () {
    $account = ClaudeAccount::factory()->system()->create();
    $repository = Repository::factory()->create(['slug' => 'acme/web', 'pr_review_enabled' => true, 'is_active' => true, 'unattended_claude_account_id' => $account->id]);
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => $repository->slug, 'pr_url' => 'https://github.com/acme/web/pull/1', 'context' => json_encode(['pr_number' => 1, 'head_sha' => 'a', 'base_sha' => 'b', 'title' => 'T', 'body' => '', 'author' => 'me', 'review_scope' => 'full', 'incremental_base_sha' => null])]);
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(sessionId: 's', resultSummary: 'x', costUsd: 0, numTurns: 1, durationMs: 1, isError: true, rawOutput: ''));
    $sandbox = mock(IncusSandboxManager::class)->shouldIgnoreMissing();
    $sandbox->shouldReceive('create')->andReturn('c');
    $sandbox->shouldReceive('run')->andReturn(Process::result(output: 'x', exitCode: 0));
    app()->instance(IncusSandboxManager::class, $sandbox);

    (new RunYakReviewJob($task))->handle($fake);

    expect($fake->lastCall()->account?->is($account))->toBeTrue();
});

it('binds the brokered runner', function () {
    expect(app(AgentRunner::class))->toBeInstanceOf(BrokeredAgentRunner::class);
});
