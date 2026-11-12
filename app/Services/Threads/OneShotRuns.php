<?php

namespace App\Services\Threads;

use App\Agents\StreamEventHandler;
use App\DataTransferObjects\AgentRunRequest;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\StreamEntry;
use App\Enums\ClaudeAccountStatus;
use App\Enums\TurnState;
use App\Exceptions\AccountSlotUnavailable;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\ClaudeAccounts\AccountFailure;
use App\Services\ClaudeAccounts\AccountFailureKind;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Support\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One turn through the broker for work that has no thread (PR review, repo
 * setup). The runner starts in the job's own sandbox on the channel
 * `runs:{taskRunId}`; the sandbox holds a placeholder bound to the run, never
 * a credential. The run holds one turn slot of the account for its duration.
 */
class OneShotRuns
{
    private const SANDBOX_USER_ID = 1001;

    private const WORKING_DIRECTORY = '/workspace';

    private const CLAUDE_CONFIG_DIRECTORY = '/home/yak/.claude';

    private const MCP_CONFIG_PATH = '/home/yak/mcp-config.json';

    private const POLL_MILLISECONDS = 100;

    private const HEARTBEAT_SECONDS = 30;

    public function __construct(
        private readonly BrokerStreams $streams,
        private readonly AuthProxyPlaceholders $placeholders,
        private readonly AccountConcurrency $concurrency,
        private readonly IncusSandboxManager $sandbox,
        private readonly RunawayGuard $guard,
        private readonly ClaudeModelCatalog $models,
    ) {}

    /**
     * @throws AccountSlotUnavailable when the account has no free turn slot
     */
    public function run(AgentRunRequest $request, TaskRun $run, ClaudeAccount $account): AgentRunResult
    {
        $containerIp = $this->containerIp($request->containerName);

        if (! $this->concurrency->acquire($account, $run)) {
            $run->forceFill(['turn_state' => TurnState::Pending])->save();

            throw new AccountSlotUnavailable("No free turn slot on Claude account {$account->id}");
        }

        $now = CarbonImmutable::now();
        $run->forceFill([
            'agent_started_at' => $now,
            'oneshot_heartbeat_at' => $now,
            'oneshot_deadline_at' => $now->addSeconds($request->timeoutSeconds),
        ])->save();

        $subject = 'run:' . $run->id;
        $channel = 'runs:' . $run->id;

        try {
            $placeholder = $this->placeholders->issue($subject, $account, $containerIp, $request->containerName);
            $this->streams->register($channel);
            $this->streams->command($channel, $this->startRunner($request, $placeholder));
            $this->streams->command($channel, ['type' => 'turn', 'uuid' => (string) Str::uuid(), 'text' => $request->prompt]);

            return $this->await($request, $run, $account, $channel, 'oneshot-' . $run->id);
        } finally {
            $this->streams->command($channel, ['type' => 'end']);
            $this->placeholders->revoke($subject);
            $this->concurrency->release($account, $run);
            $this->streams->unregister($channel);
            $this->streams->delete($channel);
        }
    }

    private function await(AgentRunRequest $request, TaskRun $run, ClaudeAccount $account, string $channel, string $consumer): AgentRunResult
    {
        $startedAt = CarbonImmutable::now();
        $deadline = $startedAt->addSeconds($request->timeoutSeconds);
        $tracker = new TurnTracker((int) config('yak.threads.turn_grace_seconds'));
        $handler = new StreamEventHandler($request->task ?? throw new RuntimeException('A one-shot run needs a task to log to'));
        $failure = $resultEvent = $exitCode = $runnerError = null;
        $assistantMessages = 0;
        $ended = false;
        $lastHeartbeat = $startedAt;

        while (! $ended) {
            if (CarbonImmutable::now() >= $deadline) {
                $this->streams->command($channel, ['type' => 'kill']);

                return AgentRunResult::failure("The agent run timed out after {$request->timeoutSeconds} seconds", '{}');
            }

            if (CarbonImmutable::now() >= $lastHeartbeat->addSeconds(self::HEARTBEAT_SECONDS)) {
                $lastHeartbeat = CarbonImmutable::now();
                $run->forceFill(['oneshot_heartbeat_at' => $lastHeartbeat])->save();
            }

            $entries = $this->streams->read([$channel], $consumer, 1000, 100);

            foreach ($entries as $entry) {
                $line = $this->lineOf($entry);

                if ($line !== null) {
                    if ($entry->fromBroker) {
                        $exitCode = (int) ($line['exit_code'] ?? 0);
                    } else {
                        $handler->handle($line);
                        $runnerError ??= StreamEventHandler::runnerError($line);
                        $assistantMessages += ($line['type'] ?? null) === 'assistant' ? 1 : 0;
                        $resultEvent = ($line['type'] ?? null) === 'result' ? $line : $resultEvent;
                    }

                    $change = $tracker->apply($line, CarbonImmutable::now());
                    $failure ??= $change->failure;
                    $ended = $ended || $change->ended;
                }

                $this->streams->ack($entry);
            }

            $ended = $tracker->tick(CarbonImmutable::now())->ended || $ended;

            if (! $ended && ($reason = $this->guard->check($run, CarbonImmutable::now(), $assistantMessages)) !== null) {
                $this->streams->command($channel, ['type' => 'interrupt']);
                $this->streams->command($channel, ['type' => 'kill']);

                return AgentRunResult::failure($this->guard->message($reason, $assistantMessages), '{}');
            }

            if (! $ended && $entries === []) {
                Sleep::for(self::POLL_MILLISECONDS)->milliseconds();
            }
        }

        if ($failure !== null) {
            return $this->failed($failure, $account);
        }

        if ($resultEvent === null) {
            $reason = $runnerError === null || $runnerError === '' ? '' : ": {$runnerError}";

            return AgentRunResult::failure("The agent runner exited before the turn ended (exit code {$exitCode}){$reason}", '{}');
        }

        return TurnResult::fromEvent(
            $resultEvent,
            $assistantMessages,
            (int) $startedAt->diffInMilliseconds(CarbonImmutable::now(), true),
            (float) $tracker->state()['cost_usd'],
        )->withStats($handler->getStats());
    }

    /**
     * The redacted runner line of an entry, or the runner_exited marker for
     * the broker's own exit line. Other broker lines, and runner lines that
     * pretend to be the broker's, are dropped.
     *
     * @return array<string, mixed>|null
     */
    private function lineOf(StreamEntry $entry): ?array
    {
        if ($entry->fromBroker) {
            return BrokerLine::event($entry->line) === 'runner_exited'
                ? ['type' => 'broker.runner_exited', 'exit_code' => $entry->line['exit_code'] ?? null]
                : null;
        }

        if (str_starts_with((string) ($entry->line['type'] ?? ''), 'broker')) {
            return null;
        }

        $json = json_encode($entry->line, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

        return json_decode(SecretRedactor::redact($json), true) ?? [];
    }

    private function failed(AccountFailure $failure, ClaudeAccount $account): AgentRunResult
    {
        if ($failure->kind === AccountFailureKind::AuthFailed) {
            $account->forceFill(['status' => ClaudeAccountStatus::Invalid])->save();

            return AgentRunResult::failure('The Claude account was rejected: authentication failed', '{}');
        }

        return AgentRunResult::failure('The Claude account has reached its usage limit', '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private function startRunner(AgentRunRequest $request, string $placeholder): array
    {
        return [
            'type' => 'start_runner',
            'container' => $request->containerName,
            'uid' => self::SANDBOX_USER_ID,
            'cwd' => self::WORKING_DIRECTORY,
            'start' => [
                'type' => 'start',
                'model' => $this->models->resolve($request->model),
                'appendSystemPrompt' => $request->systemPrompt,
                'permissionMode' => 'bypassPermissions',
                'cwd' => self::WORKING_DIRECTORY,
                'additionalDirectories' => [],
                'mcpServers' => (object) [],
                'mcpConfigPath' => $request->mcpConfigPath === null || $request->mcpConfigPath === '' ? null : self::MCP_CONFIG_PATH,
                'settingSources' => ['user', 'project', 'local'],
                'resume' => null,
                'claudeConfigDir' => self::CLAUDE_CONFIG_DIRECTORY,
                'placeholder' => $placeholder,
                'anthropicBaseUrl' => (string) config('yak.threads.auth_proxy_url'),
                'disallowedTools' => ['ScheduleWakeup', 'CronCreate', 'CronDelete', 'CronList'],
            ],
        ];
    }

    /**
     * The sandbox's bridge address, which the auth proxy binds the placeholder to.
     */
    private function containerIp(string $containerName): string
    {
        return SandboxAddress::fromIpAddr($this->sandbox->run($containerName, SandboxAddress::command(), timeout: 10, asRoot: true)->output());
    }
}
