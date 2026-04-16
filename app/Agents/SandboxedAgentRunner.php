<?php

namespace App\Agents;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ClaudeAuthException;
use App\Models\YakTask;
use App\Services\ClaudeAuthDetector;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Agent runner that executes Claude Code inside an Incus sandbox container.
 *
 * The sandbox is created by the job layer (SetupYakJob, RunYakJob, etc.)
 * and its container name is passed via the AgentRunRequest's containerName.
 * This runner executes `claude -p` inside that container via `incus exec`,
 * streaming stdout line-by-line through StreamEventHandler.
 */
class SandboxedAgentRunner implements AgentRunner
{
    /**
     * After Claude emits its `result` event, we wait this many seconds
     * for the `incus exec` process to exit naturally before terminating
     * it. Backgrounded services inside the sandbox (e.g. `php artisan
     * serve &`) can keep the exec pipe open indefinitely by inheriting
     * stdout; the grace period bounds that wedge.
     */
    public function __construct(
        private readonly IncusSandboxManager $sandbox,
        private readonly float $postResultGraceSeconds = 15.0,
        private readonly float $streamIdleTimeoutSeconds = 600.0,
        private readonly int $streamPollIntervalSeconds = 5,
    ) {}

    public function run(AgentRunRequest $request): AgentRunResult
    {
        $this->refreshClaude($request);
        $this->logPrompts($request);

        if ($request->task) {
            return $this->runStreaming($request);
        }

        return $this->runBatch($request);
    }

    /**
     * Record the exact prompt + system prompt sent to Claude on this run
     * to task_logs, so the timeline can show exactly what the agent was
     * asked to do. Skipped when no task is attached (batch mode).
     */
    private function logPrompts(AgentRunRequest $request): void
    {
        if ($request->task === null) {
            return;
        }

        $summary = $request->isResume()
            ? "Resumed Claude session ({$request->resumeSessionId})"
            : 'Dispatching Claude with task prompt';

        TaskLogger::info($request->task, $summary, [
            'type' => 'prompt',
            'model' => $request->model,
            'max_turns' => $request->maxTurns,
            'max_budget_usd' => $request->maxBudgetUsd,
            'resume_session_id' => $request->resumeSessionId,
            'mcp_config_path' => $request->mcpConfigPath,
            'prompt' => $request->prompt,
            'system_prompt' => $request->systemPrompt,
        ]);
    }

    /**
     * Update the Claude CLI inside the sandbox to the latest release
     * before invoking `claude -p`. Best-effort: on any failure we log a
     * warning and proceed with whatever version is already installed.
     *
     * Runs as root because the npm global install lives under
     * /usr/lib/node_modules, which the `yak` user cannot write.
     */
    private function refreshClaude(AgentRunRequest $request): void
    {
        $containerName = $request->containerName;
        if ($containerName === '') {
            return;
        }

        $versionBefore = $this->claudeVersion($containerName);

        try {
            $updateResult = $this->sandbox->run(
                $containerName,
                'npm install -g @anthropic-ai/claude-code@latest 2>&1',
                timeout: 60,
                asRoot: true,
            );
        } catch (Throwable $e) {
            $this->logRefreshWarning($request->task, 'Claude CLI refresh threw', [
                'container' => $containerName,
                'error' => $e->getMessage(),
                'version_before' => $versionBefore,
            ]);

            return;
        }

        if (! $updateResult->successful()) {
            $this->logRefreshWarning($request->task, 'Claude CLI refresh exited non-zero', [
                'container' => $containerName,
                'exit_code' => $updateResult->exitCode(),
                'output' => substr((string) $updateResult->output(), -500),
                'version_before' => $versionBefore,
            ]);

            return;
        }

        $versionAfter = $this->claudeVersion($containerName);
        $metadata = [
            'container' => $containerName,
            'version_before' => $versionBefore,
            'version_after' => $versionAfter,
        ];

        Log::channel('yak')->info('Claude CLI refreshed in sandbox', $metadata);

        if ($request->task !== null) {
            // Show a different line when the refresh was a no-op so
            // the arrow isn't misleading (e.g. "2.1.110 → 2.1.110").
            // The user still gets confirmation the update ran.
            $line = $versionBefore === $versionAfter
                ? "Claude CLI {$versionAfter} (already up to date)"
                : "Claude CLI {$versionBefore} → {$versionAfter}";

            TaskLogger::info($request->task, $line, $metadata);
        }
    }

    /**
     * Ask the sandbox's claude binary to report its version. Returns
     * "unknown" if the call fails, so refresh logging is always safe.
     */
    private function claudeVersion(string $containerName): string
    {
        try {
            $result = $this->sandbox->run($containerName, 'claude --version', timeout: 10, asRoot: true);
            if (! $result->successful()) {
                return 'unknown';
            }

            return trim((string) $result->output());
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function logRefreshWarning(?YakTask $task, string $message, array $metadata): void
    {
        Log::channel('yak')->warning($message, $metadata);

        if ($task !== null) {
            TaskLogger::warning($task, $message, $metadata);
        }
    }

    private function runStreaming(AgentRunRequest $request): AgentRunResult
    {
        assert($request->task !== null);

        $containerName = $request->containerName;
        $command = $this->buildClaudeCommand($request);
        $handler = new StreamEventHandler($request->task);

        Log::channel('yak')->info('Claude stream starting (sandboxed)', [
            'task_id' => $request->task->id,
            'container' => $containerName,
            'command_length' => strlen($command),
        ]);

        [$process, $pipes] = $this->sandbox->streamExec($containerName, $command);

        fclose($pipes[0]); // Close stdin

        $lineCount = 0;
        $stdout = $pipes[1];
        $stderr = $pipes[2];

        stream_set_blocking($stdout, false);

        $lastLineAt = microtime(true);
        $resultReceivedAt = null;
        $forcedTermination = null;

        while (true) {
            $read = [$stdout];
            $write = null;
            $except = null;

            $ready = @stream_select($read, $write, $except, $this->streamPollIntervalSeconds);

            if ($ready === false) {
                continue;
            }

            if ($ready > 0) {
                $line = fgets($stdout);
                if ($line === false) {
                    break;
                }
                $lineCount++;
                $lastLineAt = microtime(true);
                $this->processLine($line, $handler);

                if ($resultReceivedAt === null && $handler->getResultEvent() !== null) {
                    $resultReceivedAt = microtime(true);
                }
            } else {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    while (($line = fgets($stdout)) !== false) {
                        $lineCount++;
                        $this->processLine($line, $handler);
                    }
                    break;
                }

                $now = microtime(true);

                if ($resultReceivedAt !== null && ($now - $resultReceivedAt) >= $this->postResultGraceSeconds) {
                    Log::channel('yak')->info('Claude stream: terminating after post-result grace', [
                        'task_id' => $request->task->id,
                        'container' => $containerName,
                        'grace_seconds' => $this->postResultGraceSeconds,
                    ]);
                    proc_terminate($process);
                    $forcedTermination = 'post_result_grace';

                    continue;
                }

                if ($resultReceivedAt === null && ($now - $lastLineAt) >= $this->streamIdleTimeoutSeconds) {
                    Log::channel('yak')->warning('Claude stream: idle timeout, terminating', [
                        'task_id' => $request->task->id,
                        'container' => $containerName,
                        'idle_seconds' => $this->streamIdleTimeoutSeconds,
                    ]);
                    proc_terminate($process);
                    $forcedTermination = 'stream_idle_timeout';

                    continue;
                }
            }
        }

        $stderrOutput = stream_get_contents($stderr) ?: '';

        fclose($stdout);
        fclose($stderr);

        $exitCode = proc_close($process);

        Log::channel('yak')->info('Claude stream completed (sandboxed)', [
            'task_id' => $request->task->id,
            'container' => $containerName,
            'lines' => $lineCount,
            'exit_code' => $exitCode,
            'has_result' => $handler->getResultEvent() !== null,
            'stderr_length' => strlen($stderrOutput),
            'forced_termination' => $forcedTermination,
        ]);

        if ($stderrOutput !== '') {
            Log::channel('yak')->warning('Claude stream stderr (sandboxed)', [
                'task_id' => $request->task->id,
                'stderr' => substr($stderrOutput, 0, 2000),
            ]);
        }

        $resultEvent = $handler->getResultEvent();

        if ($resultEvent !== null) {
            Log::channel('yak')->info('Claude result event (sandboxed)', [
                'task_id' => $request->task->id,
                'is_error' => $resultEvent['is_error'] ?? false,
                'result' => substr((string) ($resultEvent['result'] ?? ''), 0, 500),
                'num_turns' => $resultEvent['num_turns'] ?? null,
                'total_cost_usd' => $resultEvent['total_cost_usd'] ?? null,
            ]);

            return ClaudeCodeOutputParser::parse(json_encode($resultEvent, JSON_THROW_ON_ERROR));
        }

        if ($stderrOutput !== '') {
            return AgentRunResult::failure($stderrOutput, '');
        }

        $terminationNote = $forcedTermination === 'stream_idle_timeout'
            ? ' — terminated after stream idle timeout'
            : '';

        return AgentRunResult::failure(
            "Claude Code stream ended without result event (lines={$lineCount}, exit={$exitCode}){$terminationNote}",
            '',
        );
    }

    private function runBatch(AgentRunRequest $request): AgentRunResult
    {
        $containerName = $request->containerName;
        $command = $this->buildClaudeCommand($request);

        $result = $this->sandbox->run($containerName, $command, $request->timeoutSeconds);

        if (ClaudeAuthDetector::isAuthError($result)) {
            throw new ClaudeAuthException(ClaudeAuthDetector::formatErrorMessage($result));
        }

        return ClaudeCodeOutputParser::parse(trim($result->output()));
    }

    private function processLine(string $line, StreamEventHandler $handler): void
    {
        $line = trim($line);

        if ($line === '') {
            return;
        }

        /** @var array<string, mixed>|null $event */
        $event = json_decode($line, true);

        if (! is_array($event)) {
            return;
        }

        $handler->handle($event);
    }

    /**
     * Build the Claude CLI command that runs inside the sandbox.
     *
     * `IncusSandboxManager::run/streamExec` already wraps commands in
     * `sudo -u yak`, so we invoke `claude` directly here. Passthrough
     * env vars (yak.agent_passthrough_env) are injected at the
     * container level by `IncusSandboxManager::configureEnvironment()`
     * rather than prepended to every command, so nothing extra is
     * needed here.
     */
    private function buildClaudeCommand(AgentRunRequest $request): string
    {
        $streaming = $request->task !== null;
        $outputFormat = $streaming ? 'stream-json' : 'json';
        $verboseFlag = $streaming ? ' --verbose' : '';

        $workspacePath = (string) config('yak.sandbox.workspace_path', '/workspace');

        $command = sprintf(
            'cd %s && claude -p %s --dangerously-skip-permissions --output-format %s%s --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
            escapeshellarg($workspacePath),
            escapeshellarg($request->prompt),
            $outputFormat,
            $verboseFlag,
            escapeshellarg($request->model),
            $request->maxTurns,
            number_format($request->maxBudgetUsd, 2, '.', ''),
            escapeshellarg($request->systemPrompt),
        );

        if ($request->isResume()) {
            $command .= sprintf(' --resume %s', escapeshellarg((string) $request->resumeSessionId));
        }

        if ($request->mcpConfigPath !== null && $request->mcpConfigPath !== '') {
            // MCP config is pushed into the container at /home/yak/mcp-config.json
            $command .= ' --mcp-config /home/yak/mcp-config.json';
        }

        return $command;
    }
}
