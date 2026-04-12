<?php

namespace App\Agents;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ClaudeAuthException;
use App\Services\ClaudeAuthDetector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

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

        $process = SymfonyProcess::fromShellCommandline($command, $request->workingDirectory);
        $process->setTimeout($request->timeoutSeconds);

        $buffer = '';
        $lineCount = 0;

        $process->start();

        foreach ($process as $type => $data) {
            if ($type !== SymfonyProcess::OUT) {
                continue;
            }

            $buffer .= $data;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $lineCount++;

                $this->processLine($line, $handler);
            }
        }

        // Process remaining buffer
        if (trim($buffer) !== '') {
            $this->processLine($buffer, $handler);
            $lineCount++;
        }

        $process->wait();
        $exitCode = $process->getExitCode();

        Log::channel('yak')->info('Claude stream completed', [
            'task_id' => $request->task?->id,
            'lines' => $lineCount,
            'exit_code' => $exitCode,
            'has_result' => $handler->getResultEvent() !== null,
        ]);

        $resultEvent = $handler->getResultEvent();

        if ($resultEvent !== null) {
            return ClaudeCodeOutputParser::parse(json_encode($resultEvent, JSON_THROW_ON_ERROR));
        }

        // If we got lines but no result event, something went wrong with parsing
        $errorOutput = trim($process->getErrorOutput());

        if ($errorOutput !== '') {
            Log::channel('yak')->warning('Claude stream error output', [
                'task_id' => $request->task?->id,
                'stderr' => substr($errorOutput, 0, 500),
            ]);

            return AgentRunResult::failure($errorOutput, '');
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

        // stdbuf -oL forces line buffering so streaming output arrives promptly
        $stdbuf = $streaming ? 'stdbuf -oL ' : '';

        $command = sprintf(
            '%sclaude -p %s --dangerously-skip-permissions --bare --output-format %s%s --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
            $stdbuf,
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
