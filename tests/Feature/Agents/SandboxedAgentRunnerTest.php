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

    public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false): ProcessResult
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

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false): ProcessResult
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
    expect($log->metadata['version_before'] ?? null)->toBe('2.1.109 (Claude Code)');
    expect($log->metadata['version_after'] ?? null)->toBe('2.1.110 (Claude Code)');
});

it('is tolerant of a failed npm install and still invokes claude -p', function () {
    $task = YakTask::factory()->create();

    $sandbox = new class extends RecordingSandboxManager
    {
        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false): ProcessResult
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
