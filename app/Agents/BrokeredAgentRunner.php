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
            $cursor = '0';
            $exited = false;

            while (! $tracker->isEnded() && ! $exited && microtime(true) - $startedAt < $request->timeoutSeconds) {
                $entries = $this->streams->readOnce($channel, $cursor, 1000);

                if ($entries === []) {
                    usleep(10_000);
                }

                foreach ($entries as $entry) {
                    $cursor = $entry->id;

                    if ($entry->fromBroker) {
                        $exited = ($entry->line['event'] ?? null) === 'runner_exited';

                        continue;
                    }

                    $handler->handle($entry->line);
                    $tracker->observe($entry->line);
                }
            }

            $timedOut = ! $tracker->isEnded();

            if (! $exited) {
                $this->streams->command($channel, ['type' => $timedOut ? 'kill' : 'end']);
                $this->waitForExit($channel, $cursor);
            }

            if ($timedOut) {
                return AgentRunResult::failure("The agent run timed out after {$request->timeoutSeconds} seconds", '{}');
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
                'mcpServers' => (object) $this->mcpServers($request->mcpConfigPath),
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
     * @return array<string, mixed>
     */
    private function mcpServers(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && is_array($data['mcpServers'] ?? null) ? $data['mcpServers'] : [];
    }
}
