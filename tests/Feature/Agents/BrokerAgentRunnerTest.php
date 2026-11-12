<?php

use App\Agents\BrokerAgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\Enums\ClaudeAccountStatus;
use App\Enums\TaskMode;
use App\Exceptions\AccountSlotUnavailable;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Telemetry\RunContext;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\FakeBrokerStreams;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSandboxManager;

/**
 * Keeps every command it was sent, since a finished run deletes its channel.
 */
class RecordingBrokerStreams extends FakeBrokerStreams
{
    /** @var list<array{channel: string, command: array<string, mixed>}> */
    public array $sent = [];

    public bool $failReads = false;

    public function command(string $channel, array $command): string
    {
        $this->sent[] = ['channel' => $channel, 'command' => $command];

        return parent::command($channel, $command);
    }

    public function read(array $channels, string $consumer, int $blockMilliseconds, int $count): array
    {
        return $this->failReads ? throw new RuntimeException('Redis went away') : parent::read($channels, $consumer, $blockMilliseconds, $count);
    }
}

beforeEach(function () {
    app()->singleton(BrokerStreams::class, RecordingBrokerStreams::class);
    Sleep::fake();
    Sleep::syncWithCarbon();

    config(['yak.threads.turn_grace_seconds' => 1]);

    $this->sandbox = new FakeSandboxManager;
    app()->instance(IncusSandboxManager::class, $this->sandbox);
    $this->streams = app(BrokerStreams::class);
    $this->sentTo = fn (string $channel): array => array_values(array_column(array_filter($this->streams->sent, fn (array $entry): bool => $entry['channel'] === $channel), 'command'));

    $this->task = YakTask::factory()->running()->create(['mode' => TaskMode::Review, 'repo' => 'acme/widgets']);
    $this->account = ClaudeAccount::factory()->system()->create();
    $this->run = TaskRun::factory()->create(['yak_task_id' => $this->task->id, 'finished_at' => null, 'outcome' => null]);
    RunContext::set($this->run->id);

    $this->channel = 'runs:' . $this->run->id;
    $this->makeRequest = fn (int $timeoutSeconds = 60): AgentRunRequest => new AgentRunRequest(
        prompt: 'Review this pull request',
        systemPrompt: 'You are Yak',
        containerName: 'yak-review-1',
        timeoutSeconds: $timeoutSeconds,
        maxBudgetUsd: 5.0,
        maxTurns: 50,
        model: 'sonnet',
        task: $this->task,
        account: $this->account,
    );
    $this->feed = function (string $fixture, ?callable $transform = null): void {
        $sequence = 0;

        foreach (file(base_path("tests/Fixtures/runner-streams/{$fixture}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
            $line = json_decode($raw, true);

            if (isset($line['fixture'])) {
                continue;
            }

            $line = $transform === null ? $line : $transform($line);
            $this->streams->pushOut($this->channel, 1, ++$sequence, $line, fromBroker: str_starts_with((string) ($line['type'] ?? ''), 'broker'));
        }
    };
});

afterEach(fn () => RunContext::clear());

it('returns the result of a natural turn end and writes only start, turn and end', function () {
    ($this->feed)('natural-turn-end');

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    expect($result->isError)->toBeFalse()
        ->and($result->resultSummary)->toBe('scrubbed result 2')
        ->and($result->costUsd)->toBeGreaterThan(0.0)
        ->and(array_column(($this->sentTo)($this->channel), 'type'))->toBe(['start_runner', 'turn', 'end'])
        ->and(array_unique(array_column($this->streams->sent, 'channel')))->toBe([$this->channel])
        ->and($this->streams->channels('runs:'))->toBe([]);
});

it('revokes the placeholder and frees the slot after success', function () {
    ($this->feed)('natural-turn-end');

    app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    $command = ($this->sentTo)($this->channel)[0];

    expect(app(AuthProxyPlaceholders::class)->resolve($command['start']['placeholder'], '10.0.0.7'))->toBeNull()
        ->and($this->run->fresh()->account_slot_held_at)->toBeNull();
});

it('revokes the placeholder and frees the slot when the run throws', function () {
    $this->streams->failReads = true;

    expect(fn () => app(BrokerAgentRunner::class)->run(($this->makeRequest)()))->toThrow(RuntimeException::class);

    $placeholder = ($this->sentTo)($this->channel)[0]['start']['placeholder'];

    expect(app(AuthProxyPlaceholders::class)->resolve($placeholder, '10.0.0.7'))->toBeNull()
        ->and($this->run->fresh()->account_slot_held_at)->toBeNull()
        ->and(array_column(($this->sentTo)($this->channel), 'type'))->toBe(['start_runner', 'turn', 'end']);
});

it('returns a failure for a usage limit', function () {
    ($this->feed)('usage-limit');

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('usage limit');
});

it('marks the account invalid when authentication fails', function () {
    ($this->feed)('authentication-failed');

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    expect($result->isError)->toBeTrue()
        ->and($this->account->fresh()->status)->toBe(ClaudeAccountStatus::Invalid);
});

it('fails when the runner exits before the turn ends', function () {
    ($this->feed)('runner-exit-mid-turn');

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('exited before the turn ended');
});

it('names the runner error when the runner exits without a result', function () {
    $this->streams->pushOut($this->channel, 1, 1, ['type' => 'runner', 'event' => 'started']);
    $this->streams->pushOut($this->channel, 1, 2, ['type' => 'runner', 'event' => 'error', 'error' => "Error: Claude Code process exited with code 1\n    at sdk.mjs:1:1"]);
    $this->streams->pushOut($this->channel, 1, 3, ['type' => 'broker.runner_exited', 'event' => 'runner_exited', 'exit_code' => 1], fromBroker: true);

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)(30));

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('exited before the turn ended (exit code 1): Error: Claude Code process exited with code 1')
        ->and($result->resultSummary)->not->toContain('sdk.mjs');
});

it('writes kill when the run times out', function () {
    $this->streams->pushOut($this->channel, 1, 1, ['type' => 'runner', 'event' => 'started']);

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)(5));

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('timed out')
        ->and(array_column(($this->sentTo)($this->channel), 'type'))->toBe(['start_runner', 'turn', 'kill', 'end']);
});

it('uses the repository unattended account before the global account', function () {
    $unattended = ClaudeAccount::factory()->system()->create(['is_default' => false]);
    Repository::factory()->create(['slug' => 'acme/widgets', 'unattended_claude_account_id' => $unattended->id]);
    ($this->feed)('natural-turn-end');

    $request = ($this->makeRequest)();
    $request = new AgentRunRequest(...[...get_object_vars($request), 'account' => null]);

    app(BrokerAgentRunner::class)->run($request);

    expect($this->run->fresh()->claude_account_id)->toBe($unattended->id);
});

it('fails cleanly when no account is available', function () {
    ClaudeAccount::query()->delete();
    $request = new AgentRunRequest('p', 's', 'yak-review-1', 60, 5.0, 50, 'sonnet', task: $this->task);

    $result = app(BrokerAgentRunner::class)->run($request);

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('No Claude account is available')
        ->and(($this->sentTo)($this->channel))->toBe([]);
});

it('throws AccountSlotUnavailable when the account has no free slot', function () {
    $this->account->update(['max_concurrent_turns' => 1]);
    TaskRun::factory()->create(['claude_account_id' => $this->account->id, 'account_slot_held_at' => now()]);

    expect(fn () => app(BrokerAgentRunner::class)->run(($this->makeRequest)()))->toThrow(AccountSlotUnavailable::class)
        ->and(($this->sentTo)($this->channel))->toBe([]);
});

it('keeps secrets out of logs, results and the runner start line', function () {
    $secret = 'sk-ant-oat01-' . str_repeat('Z', 40);
    $seenPlaceholder = null;

    ($this->feed)('natural-turn-end', function (array $line) use ($secret): array {
        if (($line['type'] ?? null) === 'assistant') {
            $line['message']['content'][0]['text'] = "found {$secret} and Bearer abcdefghijklmnopqrstuvwxyz0123456789";
        }

        if (($line['type'] ?? null) === 'result') {
            $line['result'] = "token {$secret}";
        }

        return $line;
    });

    $spy = Mockery::mock(AuthProxyPlaceholders::class)->makePartial();
    $spy->shouldReceive('issue')
        ->once()
        ->withArgs(function (string $subject, ClaudeAccount $account, string $ip, ?string $container) use (&$seenPlaceholder): bool {
            $seenPlaceholder = 'yakph-' . str_repeat('Q', 43);

            return $subject === 'run:' . $this->run->id
                && $account->is($this->account)
                && $ip === '10.0.0.7'
                && $container === 'yak-review-1';
        })
        ->andReturn('yakph-' . str_repeat('Q', 43));
    app()->instance(AuthProxyPlaceholders::class, $spy);

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    $logs = TaskLog::query()->where('yak_task_id', $this->task->id)->get()->map(fn (TaskLog $log) => $log->message . json_encode($log->metadata))->implode("\n");
    $commands = json_encode(($this->sentTo)($this->channel));

    expect($seenPlaceholder)->not->toBeNull()
        ->and($logs)->not->toContain($secret)->not->toContain('abcdefghijklmnopqrstuvwxyz0123456789')
        ->and($result->resultSummary)->toBe('token [redacted]')
        ->and(TaskLog::query()->where('message', 'like', '%[redacted]%')->exists())->toBeTrue()
        ->and($commands)->toContain('yakph-' . str_repeat('Q', 43))->not->toContain($this->account->secret);
});

it('stops a runaway run at the assistant turn limit', function () {
    config(['yak.max_turns' => 3]);

    foreach (range(1, 3) as $sequence) {
        $this->streams->pushOut($this->channel, 1, $sequence, ['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => "step {$sequence}"]]]]);
    }

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('3 assistant turns')
        ->and(array_column(($this->sentTo)($this->channel), 'type'))->toBe(['start_runner', 'turn', 'interrupt', 'kill', 'end'])
        ->and($this->run->fresh()->account_slot_held_at)->toBeNull();
});

it('stops a run that exceeds the wall clock', function () {
    config(['yak.threads.turn_wall_clock_minutes' => 1]);
    $this->streams->pushOut($this->channel, 1, 1, ['type' => 'runner', 'event' => 'started']);

    $result = app(BrokerAgentRunner::class)->run(($this->makeRequest)(7200));

    expect($result->isError)->toBeTrue()
        ->and($result->resultSummary)->toContain('1 minutes')
        ->and(array_column(($this->sentTo)($this->channel), 'type'))->toBe(['start_runner', 'turn', 'interrupt', 'kill', 'end']);
});

it('records a heartbeat while awaiting and a deadline equal to the request timeout', function () {
    $started = now();
    $seen = [];
    Sleep::whenFakingSleep(function () use (&$seen) {
        $seen = $this->run->fresh()->only(['oneshot_heartbeat_at', 'oneshot_deadline_at']);
    });

    app(BrokerAgentRunner::class)->run(($this->makeRequest)(5));

    expect($seen['oneshot_heartbeat_at'])->not->toBeNull()
        ->and($seen['oneshot_deadline_at']->diffInSeconds($started, true))->toBeBetween(4, 6);
});

it('does not forward budget or turn limits to the runner', function () {
    ($this->feed)('natural-turn-end');

    app(BrokerAgentRunner::class)->run(($this->makeRequest)());

    expect(($this->sentTo)($this->channel)[0]['start'])->not->toHaveKeys(['maxBudgetUsd', 'maxTurns']);
});
