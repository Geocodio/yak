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
use App\Services\Threads\CredentialReferences;
use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    $this->runner = new BrokeredAgentRunner($this->streams, app(CredentialReferences::class), exitWaitSeconds: 0);
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
        maxBudgetUsd: 0,
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
        ->and($commands[0]['credential_reference'])->toBeString()
        ->and($commands[1]['text'])->toBe('Review this pull request');
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

it('binds the brokered runner when threads are enabled', function () {
    config(['yak.threads.enabled' => true]);
    expect(app(AgentRunner::class))->toBeInstanceOf(BrokeredAgentRunner::class);

    config(['yak.threads.enabled' => false]);
    expect(app(AgentRunner::class))->not->toBeInstanceOf(BrokeredAgentRunner::class);
});
