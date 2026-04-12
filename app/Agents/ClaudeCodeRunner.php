<?php

namespace App\Agents;

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
        $command = $this->buildCommand($request, streaming: true);
        $handler = new StreamEventHandler($request->task);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $request->workingDirectory);

        if (! is_resource($process)) {
            return AgentRunResult::failure('Failed to start Claude process', '');
        }

        fclose($pipes[0]); // Close stdin

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        $lineCount = 0;
        $startTime = time();
        $timeout = $request->timeoutSeconds;

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                // Read any remaining output
                $remaining = stream_get_contents($pipes[1]);
                if ($remaining !== false) {
                    $buffer .= $remaining;
                }

                break;
            }

            if ((time() - $startTime) > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return AgentRunResult::failure(
                    "Claude process timed out after {$timeout}s",
                    '',
                );
            }

            $read = [$pipes[1]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) > 0) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);
                        $lineCount++;

                        $this->processLine($line, $handler);
                    }
                }
            }
        }

        // Process any remaining buffer
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            $lineCount++;
            $this->processLine($line, $handler);
        }

        if (trim($buffer) !== '') {
            $this->processLine($buffer, $handler);
            $lineCount++;
        }

        // Read stderr
        $stderr = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        Log::channel('yak')->info('Claude stream completed', [
            'task_id' => $request->task?->id,
            'lines' => $lineCount,
            'exit_code' => $exitCode,
            'has_result' => $handler->getResultEvent() !== null,
            'stderr_length' => strlen($stderr),
        ]);

        $resultEvent = $handler->getResultEvent();

        if ($resultEvent !== null) {
            return ClaudeCodeOutputParser::parse(json_encode($resultEvent, JSON_THROW_ON_ERROR));
        }

        if ($stderr !== '') {
            Log::channel('yak')->warning('Claude stream stderr', [
                'task_id' => $request->task?->id,
                'stderr' => substr($stderr, 0, 500),
            ]);

            return AgentRunResult::failure($stderr, '');
        }

        return AgentRunResult::failure(
            "Claude Code stream ended without result event (lines={$lineCount}, exit={$exitCode})",
            '',
        );
    }

    private function runBatch(AgentRunRequest $request): AgentRunResult
    {
        $command = $this->buildCommand($request, streaming: false);

        $result = Process::path($request->workingDirectory)
            ->timeout($request->timeoutSeconds)
            ->run($command);

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

    private function buildCommand(AgentRunRequest $request, bool $streaming): string
    {
        $outputFormat = $streaming ? 'stream-json' : 'json';
        $verboseFlag = $streaming ? ' --verbose' : '';

        $command = sprintf(
            'claude -p %s --dangerously-skip-permissions --bare --output-format %s%s --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
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
