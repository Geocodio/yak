<?php

namespace App\Agents;

use App\ClaudeCli;
use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ClaudeAuthException;
use App\Services\ClaudeAuthDetector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class ClaudeCodeRunner implements AgentRunner
{
    public function run(AgentRunRequest $request): AgentRunResult
    {
        if ($request->task) {
            return $this->runStreaming($request);
        }

        return $this->runBatch($request);
    }

    private function runStreaming(AgentRunRequest $request): AgentRunResult
    {
        assert($request->task !== null);

        $command = $this->buildCommand($request, streaming: true);
        $handler = new StreamEventHandler($request->task);

        Log::channel('yak')->info('Claude stream starting', [
            'task_id' => $request->task->id,
            'command_length' => strlen($command),
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $wrappedCommand = $this->wrapCommand($command);
        $process = proc_open($wrappedCommand, $descriptors, $pipes, $request->workingDirectory);

        if (! is_resource($process)) {
            return AgentRunResult::failure('Failed to start Claude process', '');
        }

        fclose($pipes[0]); // Close stdin

        $lineCount = 0;
        $stdout = $pipes[1];
        $stderr = $pipes[2];

        // Read stdout line by line using stream_select + fgets
        // Pure blocking fgets hangs in the queue worker due to Laravel's
        // pcntl signal handlers interfering with the blocking read syscall
        stream_set_blocking($stdout, false);

        while (true) {
            $read = [$stdout];
            $write = null;
            $except = null;

            // Wait up to 5 seconds for data, then check if process is alive
            $ready = @stream_select($read, $write, $except, 5);

            if ($ready === false) {
                // stream_select was interrupted (e.g. by a signal), retry
                continue;
            }

            if ($ready > 0) {
                $line = fgets($stdout);
                if ($line === false) {
                    break; // pipe closed
                }
                $lineCount++;
                $this->processLine($line, $handler);
            } else {
                // Timeout — check if process is still running
                $status = proc_get_status($process);
                if (! $status['running']) {
                    // Drain remaining output
                    while (($line = fgets($stdout)) !== false) {
                        $lineCount++;
                        $this->processLine($line, $handler);
                    }
                    break;
                }
            }
        }

        // Read any stderr after process ends
        $stderrOutput = stream_get_contents($stderr) ?: '';

        fclose($stdout);
        fclose($stderr);

        $exitCode = proc_close($process);

        Log::channel('yak')->info('Claude stream completed', [
            'task_id' => $request->task->id,
            'lines' => $lineCount,
            'exit_code' => $exitCode,
            'has_result' => $handler->getResultEvent() !== null,
            'stderr_length' => strlen($stderrOutput),
        ]);

        if ($stderrOutput !== '') {
            Log::channel('yak')->warning('Claude stream stderr', [
                'task_id' => $request->task->id,
                'stderr' => substr($stderrOutput, 0, 2000),
            ]);
        }

        $resultEvent = $handler->getResultEvent();

        if ($resultEvent !== null) {
            Log::channel('yak')->info('Claude result event', [
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

        return AgentRunResult::failure(
            "Claude Code stream ended without result event (lines={$lineCount}, exit={$exitCode})",
            '',
        );
    }

    private function runBatch(AgentRunRequest $request): AgentRunResult
    {
        $command = $this->buildCommand($request, streaming: false);

        $wrappedCommand = $this->wrapCommand($command);

        $result = Process::path($request->workingDirectory)
            ->timeout($request->timeoutSeconds)
            ->run($wrappedCommand);

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
     * Build the sudo runuser wrapper with allowlisted env vars.
     *
     * Only HOME and explicitly configured passthrough vars are forwarded
     * to the sandboxed agent — app secrets (DB_PASSWORD, APP_KEY, etc.)
     * are NOT exposed.
     */
    private function wrapCommand(string $command): string
    {
        $extraEnv = [];

        $passthrough = config('yak.agent_passthrough_env', '');
        foreach (array_filter(explode(',', $passthrough)) as $name) {
            $name = trim($name);
            $value = getenv($name);
            if ($value !== false) {
                $extraEnv[$name] = $value;
            }
        }

        return app(ClaudeCli::class)->buildWrappedCommand($command, $extraEnv);
    }

    private function buildCommand(AgentRunRequest $request, bool $streaming): string
    {
        $outputFormat = $streaming ? 'stream-json' : 'json';
        $verboseFlag = $streaming ? ' --verbose' : '';

        $command = sprintf(
            'claude -p %s --dangerously-skip-permissions --output-format %s%s --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
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
            $command .= sprintf(' --mcp-config %s', escapeshellarg($request->mcpConfigPath));
        }

        return $command;
    }
}
