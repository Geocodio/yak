<?php

namespace App\Agents;

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\Exceptions\ClaudeAuthException;
use App\Models\ClaudeAccount;
use App\Services\IncusSandboxManager;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\ThreadStreams;
use App\Services\Threads\TurnResult;
use App\Services\Threads\TurnTracker;
use Illuminate\Support\Str;

/**
 * Runs one turn through the broker on a `oneshot:{uuid}` channel for jobs that
 * have no thread (PR review, repo setup): start the runner in the job's own
 * sandbox, send the prompt, read until the turn ends, end the runner.
 * There is no cost budget; the timeout is the only limit.
 */
class BrokeredAgentRunner implements AgentRunner
{
    private const CLAUDE_CONFIG_DIRECTORY = '/home/yak/.claude';

    private const SANDBOX_USER_ID = 1001;

    public function __construct(
        private readonly ThreadStreams $streams,
        private readonly AuthProxyPlaceholders $placeholders,
        private readonly int $exitWaitSeconds = 30,
    ) {}

    public function run(AgentRunRequest $request): AgentRunResult
    {
        $task = $request->task ?? throw new \LogicException('A brokered run needs a task to log to');
        $account = $request->account;

        if (! $account instanceof ClaudeAccount) {
            throw new ClaudeAuthException('No usable Claude account for this run');
        }

        $channel = 'oneshot:' . Str::uuid();
        $placeholder = $this->placeholders->issueForContainer((string) $request->containerName, $account);
        $this->streams->register($channel);

        try {
            $this->streams->command($channel, $this->startRunnerCommand($request, $placeholder));
            $this->streams->command($channel, ['type' => 'turn', 'uuid' => $turnUuid = (string) Str::uuid(), 'text' => $request->prompt]);

            $handler = new StreamEventHandler($task);
            $tracker = new TurnTracker;
            $tracker->track($turnUuid);

            $startedAt = microtime(true);
            $deadline = min($request->timeoutSeconds, (int) config('yak.threads.turn_wall_clock_minutes') * 60);
            $cursor = '0';
            $exitLine = null;
            $stopReason = null;

            while (! $tracker->isEnded() && $exitLine === null && $stopReason === null) {
                if (microtime(true) - $startedAt >= $deadline) {
                    $stopReason = "The agent run timed out after {$deadline} seconds";

                    break;
                }

                $entries = $this->streams->readOnce($channel, $cursor, 1000);

                if ($entries === []) {
                    usleep(10_000);
                }

                foreach ($entries as $entry) {
                    $cursor = $entry->id;

                    if ($entry->fromBroker) {
                        $exitLine = ($entry->line['event'] ?? null) === 'runner_exited' ? $entry->line : null;

                        continue;
                    }

                    $handler->handle($entry->line);
                    $tracker->observe($entry->line);
                }

                if ($stopReason === null && ! $tracker->isEnded() && $handler->getStats()->assistantMessages >= $request->maxTurns) {
                    $stopReason = "The agent run stopped after {$request->maxTurns} assistant turns";
                }
            }

            if ($exitLine === null) {
                $this->streams->command($channel, ['type' => $tracker->isEnded() ? 'end' : 'kill']);
                $this->waitForExit($channel, $cursor);
            }

            if ($stopReason !== null) {
                return AgentRunResult::failure($stopReason, '{}');
            }

            if ($exitLine !== null && ! $tracker->isEnded()) {
                $exitCode = (int) ($exitLine['exit_code'] ?? 0);

                return AgentRunResult::failure(
                    $exitCode === -1 ? 'The agent runner failed to start' : "The agent runner exited before the turn ended (exit code {$exitCode})",
                    '{}',
                );
            }

            return TurnResult::fromEvent(
                $tracker->attributes()['last_result_event'],
                $handler->getStats()->assistantMessages,
                (int) round((microtime(true) - $startedAt) * 1000),
                $tracker->costUsd(),
            );
        } finally {
            $this->streams->unregister($channel);
            $this->streams->delete($channel);
            $this->placeholders->revoke($placeholder);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function startRunnerCommand(AgentRunRequest $request, string $placeholder): array
    {
        return [
            'type' => 'start_runner',
            'container' => $request->containerName,
            'uid' => self::SANDBOX_USER_ID,
            'cwd' => IncusSandboxManager::workspacePath(),
            'start' => [
                'type' => 'start',
                'model' => $request->model,
                'appendSystemPrompt' => $request->systemPrompt,
                'permissionMode' => 'bypassPermissions',
                'cwd' => IncusSandboxManager::workspacePath(),
                'additionalDirectories' => [],
                'mcpConfigPath' => $this->sandboxMcpConfigPath($request->mcpConfigPath),
                'settingSources' => ['user', 'project', 'local'],
                'resume' => null,
                'claudeConfigDir' => self::CLAUDE_CONFIG_DIRECTORY,
                'placeholder' => $placeholder,
                'anthropicBaseUrl' => (string) config('yak.threads.auth_proxy_url'),
            ],
        ];
    }

    private function waitForExit(string $channel, string $cursor): void
    {
        $deadline = microtime(true) + $this->exitWaitSeconds;

        while (microtime(true) < $deadline) {
            foreach ($this->streams->readOnce($channel, $cursor, 1000) as $entry) {
                $cursor = $entry->id;

                if ($entry->fromBroker && ($entry->line['event'] ?? null) === 'runner_exited') {
                    return;
                }
            }

            usleep(10_000);
        }
    }

    /**
     * The sandbox gets the MCP config as a file when it is created, so the start line, which goes
     * through Redis, carries only where to find it and never the servers' secrets.
     */
    private function sandboxMcpConfigPath(?string $hostPath): ?string
    {
        return $hostPath !== null && is_file($hostPath) ? IncusSandboxManager::SANDBOX_MCP_CONFIG_PATH : null;
    }
}
