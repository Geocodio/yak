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
        $command = $this->buildCommand($request);

        $result = Process::path($request->workingDirectory)
            ->timeout($request->timeoutSeconds)
            ->run($command);

        if (ClaudeAuthDetector::isAuthError($result)) {
            throw new ClaudeAuthException(ClaudeAuthDetector::formatErrorMessage($result));
        }

        return ClaudeCodeOutputParser::parse(trim($result->output()));
    }

    private function buildCommand(AgentRunRequest $request): string
    {
        $command = sprintf(
            'claude -p %s --dangerously-skip-permissions --bare --output-format json --model %s --max-turns %d --max-budget-usd %s --append-system-prompt %s',
            escapeshellarg($request->prompt),
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
