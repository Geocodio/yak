<?php

use App\Agents\SandboxedAgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Recording fake sandbox manager — captures the exact command and flags
 * each `run()` receives so tests can assert ordering and semantics.
 */
class RecordingSandboxManager extends IncusSandboxManager
{
    /** @var list<array{command: string, asRoot: bool, timeout: ?int}> */
    public array $calls = [];

    /** @var array<string, ProcessResult> */
    public array $responses = [];

    public function respondTo(string $pattern, ProcessResult $result): void
    {
        $this->responses[$pattern] = $result;
    }

    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => $timeout];

        foreach ($this->responses as $pattern => $result) {
            if (str_contains($command, $pattern)) {
                return $result;
            }
        }

        return Process::result('');
    }

    public function streamExec(string $containerName, string $command, bool $asRoot = false): array
    {
        $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => null];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('echo ""', $descriptors, $pipes);

        return [$process, $pipes];
    }
}

function buildAgentRunRequest(?YakTask $task = null): AgentRunRequest
{
    return new AgentRunRequest(
        prompt: 'do the thing',
        systemPrompt: 'you are an agent',
        containerName: 'task-test',
        timeoutSeconds: 600,
        maxBudgetUsd: 5.0,
        maxTurns: 300,
        model: 'opus',
        task: $task,
    );
}

it('updates Claude inside the sandbox before invoking claude -p', function () {
    $sandbox = new RecordingSandboxManager;
    $sandbox->respondTo('claude --version', Process::result("2.1.109 (Claude Code)\n"));
    $sandbox->respondTo('npm install -g @anthropic-ai/claude-code@latest', Process::result('updated'));

    $runner = new SandboxedAgentRunner($sandbox);
    $runner->run(buildAgentRunRequest());

    // First three calls, in order: version probe, npm install, post-install version probe.
    expect($sandbox->calls[0]['command'])->toContain('claude --version');
    expect($sandbox->calls[0]['asRoot'])->toBeTrue();
    expect($sandbox->calls[1]['command'])->toContain('npm install -g @anthropic-ai/claude-code@latest');
    expect($sandbox->calls[1]['asRoot'])->toBeTrue();
    expect($sandbox->calls[1]['timeout'])->toBe(60);
    expect($sandbox->calls[2]['command'])->toContain('claude --version');

    // The subsequent invocation is the claude -p streaming command (since no task, batch mode — still after refresh).
    $lastCall = end($sandbox->calls);
    expect($lastCall['command'])->toContain('claude -p');
});

it('logs the before/after Claude versions on the task when a task is attached', function () {
    $task = YakTask::factory()->create();

    $sandbox = new RecordingSandboxManager;
    $callCount = 0;
    $sandbox->respondTo('claude --version', Process::result("2.1.109 (Claude Code)\n"));
    // Second version probe returns the upgraded version.
    $sandbox->responses = [];
    $sandbox->responses['npm install -g @anthropic-ai/claude-code@latest'] = Process::result('updated');

    // Override claudeVersion probe to return different values for pre/post.
    $sandbox = new class extends RecordingSandboxManager
    {
        private int $versionCalls = 0;

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null): ProcessResult
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => $timeout];

            if (str_contains($command, 'claude --version')) {
                $this->versionCalls++;

                return $this->versionCalls === 1
                    ? Process::result("2.1.109 (Claude Code)\n")
                    : Process::result("2.1.110 (Claude Code)\n");
            }

            if (str_contains($command, 'npm install')) {
                return Process::result('updated');
            }

            return Process::result('');
        }
    };

    $runner = new SandboxedAgentRunner($sandbox);
    $runner->run(buildAgentRunRequest($task));

    $log = TaskLog::where('yak_task_id', $task->id)
        ->where('message', 'like', 'Claude CLI %')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->message)->toContain('2.1.109 (Claude Code)');
    expect($log->message)->toContain('2.1.110 (Claude Code)');
    expect($log->message)->toContain('→');
    expect($log->metadata['version_before'] ?? null)->toBe('2.1.109 (Claude Code)');
    expect($log->metadata['version_after'] ?? null)->toBe('2.1.110 (Claude Code)');
});

it('logs a friendlier line when Claude was already up to date (no version change)', function () {
    $task = YakTask::factory()->create();

    $sandbox = new class extends RecordingSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null): ProcessResult
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => $timeout];

            if (str_contains($command, 'claude --version')) {
                return Process::result("2.1.110 (Claude Code)\n");
            }

            if (str_contains($command, 'npm install')) {
                return Process::result('already up to date');
            }

            return Process::result('');
        }
    };

    $runner = new SandboxedAgentRunner($sandbox);
    $runner->run(buildAgentRunRequest($task));

    $log = TaskLog::where('yak_task_id', $task->id)
        ->where('message', 'like', 'Claude CLI %')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->message)->toBe('Claude CLI 2.1.110 (Claude Code) (already up to date)');
    expect($log->message)->not->toContain('→');
});

it('terminates the exec process after the post-result grace period, preserving the success result', function () {
    $task = YakTask::factory()->create();

    $sandbox = new class extends RecordingSandboxManager
    {
        public function streamExec(string $containerName, string $command, bool $asRoot = false): array
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => null];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $resultEvent = json_encode([
                'type' => 'result',
                'is_error' => false,
                'result' => 'setup complete',
                'num_turns' => 5,
                'total_cost_usd' => 0.42,
                'duration_ms' => 1000,
                'session_id' => 'sess_grace',
            ]);

            // Emit the result then replace the shell with `sleep` so SIGTERM
            // from proc_terminate kills the sleep directly — mirrors the
            // wedge we see in prod when backgrounded sandbox services hold
            // stdout open after claude exits.
            $shellCommand = sprintf("printf '%%s\\n' '%s'; exec sleep 20", $resultEvent);
            $process = proc_open(['sh', '-c', $shellCommand], $descriptors, $pipes);

            return [$process, $pipes];
        }
    };

    $runner = new SandboxedAgentRunner(
        sandbox: $sandbox,
        postResultGraceSeconds: 0.5,
        streamIdleTimeoutSeconds: 60,
        streamPollIntervalSeconds: 0,
    );

    $start = microtime(true);
    $result = $runner->run(buildAgentRunRequest($task));
    $elapsed = microtime(true) - $start;

    expect($result->isError)->toBeFalse();
    expect($result->resultSummary)->toBe('setup complete');
    expect($elapsed)->toBeLessThan(5.0);
});

it('escalates to SIGKILL when the exec child ignores SIGTERM (e.g. backgrounded docker build holding the pipe)', function () {
    $task = YakTask::factory()->create();

    $sandbox = new class extends RecordingSandboxManager
    {
        public function streamExec(string $containerName, string $command, bool $asRoot = false): array
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => null];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $resultEvent = json_encode([
                'type' => 'result',
                'is_error' => false,
                'result' => 'looks done',
                'num_turns' => 3,
                'total_cost_usd' => 0.1,
                'duration_ms' => 500,
                'session_id' => 'sess_sigkill',
            ]);

            // Single perl process that ignores SIGTERM and sleeps.
            // Using `sh -c 'printf; sleep'` would leak stdout to the
            // `sleep` child, keeping the pipe alive even after SIGKILLing
            // sh. Staying in one process means SIGKILL actually closes
            // the pipe.
            $perl = sprintf(
                '$| = 1; $SIG{TERM} = "IGNORE"; print %s, "\n"; while (1) { sleep 1 }',
                var_export($resultEvent, true),
            );
            $process = proc_open(['perl', '-e', $perl], $descriptors, $pipes);

            return [$process, $pipes];
        }
    };

    $runner = new SandboxedAgentRunner(
        sandbox: $sandbox,
        postResultGraceSeconds: 0.3,
        streamIdleTimeoutSeconds: 60,
        streamPollIntervalSeconds: 0,
        heartbeatIntervalSeconds: 60,
        sigkillEscalationSeconds: 0.3,
    );

    $start = microtime(true);
    $result = $runner->run(buildAgentRunRequest($task));
    $elapsed = microtime(true) - $start;

    // The success result from the result event is preserved even though
    // we had to SIGKILL. And the whole thing completes quickly rather
    // than blocking in proc_close for the full 30s sleep.
    expect($result->isError)->toBeFalse();
    expect($result->resultSummary)->toBe('looks done');
    expect($elapsed)->toBeLessThan(5.0);
});

it('terminates and reports failure when the stream is idle with no result event', function () {
    $task = YakTask::factory()->create();

    $sandbox = new class extends RecordingSandboxManager
    {
        public function streamExec(string $containerName, string $command, bool $asRoot = false): array
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => null];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            // Emit nothing, just hold stdout open — simulates Claude silently hanging.
            $process = proc_open(['sleep', '20'], $descriptors, $pipes);

            return [$process, $pipes];
        }
    };

    $runner = new SandboxedAgentRunner(
        sandbox: $sandbox,
        postResultGraceSeconds: 60,
        streamIdleTimeoutSeconds: 0.5,
        streamPollIntervalSeconds: 0,
    );

    $start = microtime(true);
    $result = $runner->run(buildAgentRunRequest($task));
    $elapsed = microtime(true) - $start;

    expect($result->isError)->toBeTrue();
    expect($result->resultSummary)->toContain('terminated after stream idle timeout');
    expect($elapsed)->toBeLessThan(5.0);
});

it('heartbeats the task while the stream is silent so the orphan reaper spares it', function () {
    $task = YakTask::factory()->running()->create();
    $originalUpdatedAt = now()->subMinutes(20);
    YakTask::query()->where('id', $task->id)->update(['updated_at' => $originalUpdatedAt]);

    $sandbox = new class extends RecordingSandboxManager
    {
        public function streamExec(string $containerName, string $command, bool $asRoot = false): array
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => null];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            // Silent, long-running process — mirrors Claude waiting on a
            // long Bash call (e.g. docker build) with no stream output.
            $process = proc_open(['sleep', '2'], $descriptors, $pipes);

            return [$process, $pipes];
        }
    };

    $runner = new SandboxedAgentRunner(
        sandbox: $sandbox,
        postResultGraceSeconds: 60,
        streamIdleTimeoutSeconds: 5,
        streamPollIntervalSeconds: 0,
        heartbeatIntervalSeconds: 0.1,
    );

    $runner->run(buildAgentRunRequest($task));

    expect($task->fresh()->updated_at->greaterThan($originalUpdatedAt))->toBeTrue();
});

it('records the exact prompt and system prompt on task_logs when a task is attached', function () {
    $task = YakTask::factory()->create();

    $sandbox = new RecordingSandboxManager;
    $runner = new SandboxedAgentRunner($sandbox);

    $request = new AgentRunRequest(
        prompt: 'implement feature X',
        systemPrompt: 'you are yak agent — follow repo CLAUDE.md',
        containerName: 'task-prompt-test',
        timeoutSeconds: 600,
        maxBudgetUsd: 5.0,
        maxTurns: 200,
        model: 'opus',
        task: $task,
    );

    $runner->run($request);

    $log = TaskLog::where('yak_task_id', $task->id)
        ->where('metadata->type', 'prompt')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->message)->toBe('Dispatching Claude with task prompt');
    expect($log->metadata['prompt'] ?? null)->toBe('implement feature X');
    expect($log->metadata['system_prompt'] ?? null)->toBe('you are yak agent — follow repo CLAUDE.md');
    expect($log->metadata['model'] ?? null)->toBe('opus');
    expect($log->metadata['max_turns'] ?? null)->toBe(200);
});

it('labels a resumed session distinctly in the prompt log', function () {
    $task = YakTask::factory()->create();
    $sandbox = new RecordingSandboxManager;
    $runner = new SandboxedAgentRunner($sandbox);

    $request = new AgentRunRequest(
        prompt: 'CI failed: syntax error on line 42',
        systemPrompt: 'system',
        containerName: 'task-resume-test',
        timeoutSeconds: 600,
        maxBudgetUsd: 5.0,
        maxTurns: 200,
        model: 'opus',
        resumeSessionId: 'sess_abc123',
        task: $task,
    );

    $runner->run($request);

    $log = TaskLog::where('yak_task_id', $task->id)
        ->where('metadata->type', 'prompt')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->message)->toBe('Resumed Claude session (sess_abc123)');
    expect($log->metadata['resume_session_id'] ?? null)->toBe('sess_abc123');
});

it('is tolerant of a failed npm install and still invokes claude -p', function () {
    $task = YakTask::factory()->create();

    $sandbox = new class extends RecordingSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null): ProcessResult
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => $timeout];

            if (str_contains($command, 'claude --version')) {
                return Process::result("2.1.109 (Claude Code)\n");
            }
            if (str_contains($command, 'npm install')) {
                return Process::result('npm ERR! network refused', exitCode: 1);
            }

            return Process::result('');
        }
    };

    $runner = new SandboxedAgentRunner($sandbox);
    $runner->run(buildAgentRunRequest($task));

    $warning = TaskLog::where('yak_task_id', $task->id)
        ->where('level', 'warning')
        ->first();

    expect($warning)->not->toBeNull();
    expect($warning->message)->toBe('Claude CLI refresh exited non-zero');

    // Claude -p was still invoked (streaming) after the failed refresh.
    $claudeCall = collect($sandbox->calls)->first(fn (array $c): bool => str_contains($c['command'], 'claude -p'));
    expect($claudeCall)->not->toBeNull();
});

test('claude command contains no environment-export prefix (env comes from Incus container config, not the shell)', function () {
    // Guard-rail for the layering decision: passthrough env is set on
    // the container via IncusSandboxManager::configureEnvironment(),
    // not via shell exports in the runner. If someone re-introduces
    // the old approach this test should catch it.
    config()->set('yak.agent_passthrough_env', 'NODE_AUTH_TOKEN');
    putenv('NODE_AUTH_TOKEN=ghp_should_not_appear_here');

    $task = YakTask::factory()->running()->create();
    $sandbox = new RecordingSandboxManager;
    $sandbox->respondTo('claude --version', Process::result('claude 1.0.0'));

    $runner = new SandboxedAgentRunner($sandbox);
    $runner->run(buildAgentRunRequest($task));

    $claudeCall = collect($sandbox->calls)->first(fn (array $c): bool => str_contains($c['command'], 'claude -p'));
    expect($claudeCall)->not->toBeNull();
    expect($claudeCall['command'])
        ->not->toContain('export NODE_AUTH_TOKEN')
        ->not->toContain('ghp_should_not_appear_here');

    putenv('NODE_AUTH_TOKEN');
});

test('user prompt is piped to claude -p via stdin, never embedded as an argv argument', function () {
    // Regression: a 144KB PR review prompt (task 4505) overflowed
    // Linux's MAX_ARG_STRLEN (~128KB per argv entry) when embedded in
    // the shell command, producing
    // "proc_open(): posix_spawn() failed: Argument list too long".
    // The prompt must travel through stdin — which has no ARG_MAX —
    // not through argv.
    $task = YakTask::factory()->running()->create();

    $largePrompt = str_repeat('A', 200_000) . '[SENTINEL-MARKER]';
    $capturedStdin = tempnam(sys_get_temp_dir(), 'yak-stdin-');

    $sandbox = new class($capturedStdin) extends RecordingSandboxManager
    {
        public function __construct(public string $stdinCaptureFile) {}

        public function streamExec(string $containerName, string $command, bool $asRoot = false): array
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => null];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            // Drain stdin to a capture file, then emit a valid result
            // event so the runner exits cleanly. bash makes the stdin
            // redirection a separate process step from the printf,
            // which lets us capture AFTER the runner finishes writing.
            $resultEvent = json_encode([
                'type' => 'result',
                'is_error' => false,
                'result' => 'prompt received',
                'num_turns' => 1,
                'total_cost_usd' => 0.0,
                'duration_ms' => 1,
                'session_id' => 'sess_stdin_capture',
            ]);

            $shellCommand = sprintf(
                'cat > %s; printf "%%s\n" %s',
                escapeshellarg($this->stdinCaptureFile),
                escapeshellarg($resultEvent),
            );

            $process = proc_open(['bash', '-c', $shellCommand], $descriptors, $pipes);

            return [$process, $pipes];
        }
    };

    $runner = new SandboxedAgentRunner(
        sandbox: $sandbox,
        postResultGraceSeconds: 0.5,
        streamIdleTimeoutSeconds: 30,
        streamPollIntervalSeconds: 0,
    );

    $request = new AgentRunRequest(
        prompt: $largePrompt,
        systemPrompt: 'you are yak agent',
        containerName: 'task-stdin-test',
        timeoutSeconds: 600,
        maxBudgetUsd: 5.0,
        maxTurns: 300,
        model: 'opus',
        task: $task,
    );

    $result = $runner->run($request);

    $claudeCall = collect($sandbox->calls)
        ->first(fn (array $c): bool => str_contains($c['command'], 'claude -p'));

    expect($claudeCall)->not->toBeNull();
    // argv-size guard: the shell command we hand to proc_open must
    // stay far below MAX_ARG_STRLEN regardless of prompt size.
    expect(strlen($claudeCall['command']))->toBeLessThan(10_000);
    expect($claudeCall['command'])->not->toContain('[SENTINEL-MARKER]');
    expect($claudeCall['command'])->not->toContain(str_repeat('A', 1000));

    // stdin path: the prompt arrived at the child process via the
    // stdin pipe, byte-for-byte.
    expect(file_get_contents($capturedStdin))->toBe($largePrompt);

    expect($result->isError)->toBeFalse();
    expect($result->resultSummary)->toBe('prompt received');

    @unlink($capturedStdin);
});

test('batch mode feeds the prompt to claude -p via stdin, not argv', function () {
    // Same regression as the streaming test, but for runBatch() —
    // invoked when AgentRunRequest has no task attached.
    $largePrompt = str_repeat('B', 180_000) . '[BATCH-SENTINEL]';

    $sandbox = new class extends RecordingSandboxManager
    {
        public ?string $capturedInput = null;

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null): ProcessResult
        {
            $this->calls[] = ['command' => $command, 'asRoot' => $asRoot, 'timeout' => $timeout];

            if (str_contains($command, 'claude -p')) {
                $this->capturedInput = $input;

                return Process::result(json_encode([
                    'type' => 'result',
                    'is_error' => false,
                    'result' => 'batch ok',
                    'num_turns' => 1,
                    'total_cost_usd' => 0.0,
                    'duration_ms' => 1,
                    'session_id' => 'sess_batch',
                ]));
            }

            if (str_contains($command, 'claude --version')) {
                return Process::result("2.1.118 (Claude Code)\n");
            }

            return Process::result('');
        }
    };

    $request = new AgentRunRequest(
        prompt: $largePrompt,
        systemPrompt: 'system',
        containerName: 'task-batch-stdin',
        timeoutSeconds: 600,
        maxBudgetUsd: 5.0,
        maxTurns: 50,
        model: 'opus',
    );

    $runner = new SandboxedAgentRunner($sandbox);
    $runner->run($request);

    $claudeCall = collect($sandbox->calls)
        ->first(fn (array $c): bool => str_contains($c['command'], 'claude -p'));

    expect($claudeCall)->not->toBeNull();
    expect(strlen($claudeCall['command']))->toBeLessThan(10_000);
    expect($claudeCall['command'])->not->toContain('[BATCH-SENTINEL]');
    expect($sandbox->capturedInput)->toBe($largePrompt);
});
