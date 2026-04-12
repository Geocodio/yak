<?php

namespace App\Agents;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ClaudeAuthException;
use App\Services\ClaudeAuthDetector;
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

        $process = Process::path($request->workingDirectory)
            ->timeout($request->timeoutSeconds)
            ->start($command);

        $buffer = '';

        while ($process->running()) {
            $output = $process->latestOutput();

            if ($output !== '') {
                $buffer .= $output;

                // Process complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $this->processLine($line, $handler);
                }
            }

            usleep(50000); // 50ms
        }

        // Process any remaining buffer
        if (trim($buffer) !== '') {
            $this->processLine($buffer, $handler);
        }

        // Also check for any final output after process ends
        $finalOutput = $process->latestOutput();
        if ($finalOutput !== '') {
            foreach (explode("\n", $finalOutput) as $line) {
                $this->processLine($line, $handler);
            }
        }

        $resultEvent = $handler->getResultEvent();

        if ($resultEvent !== null) {
            return ClaudeCodeOutputParser::parse(json_encode($resultEvent, JSON_THROW_ON_ERROR));
        }

        // Fallback: try to parse the full output
        $fullOutput = trim($process->output());

        if (ClaudeAuthDetector::isAuthError($process)) {
            throw new ClaudeAuthException(ClaudeAuthDetector::formatErrorMessage($process));
        }

        return ClaudeCodeOutputParser::parse($fullOutput);
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
