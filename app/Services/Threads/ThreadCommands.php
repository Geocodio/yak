<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\IncusSandboxManager;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ThreadRepositoryPrompt;

/**
 * Writes runner commands to a thread's broker channel. The channel carries the
 * thread's auth proxy placeholder, never a credential: the proxy swaps the
 * real token in on the host.
 */
class ThreadCommands
{
    private const SANDBOX_USER_ID = 1001;

    private const WORKING_DIRECTORY = '/workspace';

    private const DISALLOWED_TOOLS = ['ScheduleWakeup', 'CronCreate', 'CronDelete', 'CronList'];

    public function __construct(
        private readonly BrokerStreams $streams,
        private readonly SandboxManager $sandbox,
        private readonly AuthProxyPlaceholders $placeholders,
        private readonly ClaudeModelCatalog $models,
    ) {}

    public function channel(Thread $thread): string
    {
        return 'threads:' . $thread->id;
    }

    public function startRunner(Thread $thread, ClaudeAccount $account, ?string $resumeSessionId, string $appendSystemPrompt, ?string $workingDirectory = null): void
    {
        $workingDirectory ??= self::WORKING_DIRECTORY;
        $placeholder = $this->placeholders->issue($thread, $account, $this->containerIp($thread));

        $this->send($thread, [
            'type' => 'start_runner',
            'container' => $thread->sandbox_container,
            'uid' => self::SANDBOX_USER_ID,
            'cwd' => $workingDirectory,
            'start' => [
                'type' => 'start',
                'model' => $thread->model === null ? null : $this->models->resolve($thread->model),
                'appendSystemPrompt' => $appendSystemPrompt,
                'permissionMode' => 'bypassPermissions',
                'cwd' => $workingDirectory,
                'additionalDirectories' => ThreadRepositoryPrompt::extraDirectories($thread),
                'mcpServers' => (object) [],
                'mcpConfigPath' => $this->mcpConfigPath(),
                'settingSources' => ['user', 'project', 'local'],
                'resume' => $resumeSessionId,
                'claudeConfigDir' => $thread->claude_config_dir,
                'placeholder' => $placeholder,
                'anthropicBaseUrl' => (string) config('yak.threads.auth_proxy_url'),
                'disallowedTools' => self::DISALLOWED_TOOLS,
                'passthroughEnv' => IncusSandboxManager::passthroughNames(),
            ],
        ]);

        $thread->forceFill([
            'runner_state' => RunnerState::Starting,
            'runner_model' => $thread->model,
            'append_system_prompt' => $appendSystemPrompt,
        ])->save();
    }

    /**
     * The sandbox path of the pushed MCP config, or null when none is configured on the host.
     */
    private function mcpConfigPath(): ?string
    {
        $hostPath = config('yak.mcp_config_path');

        return is_string($hostPath) && $hostPath !== '' && file_exists($hostPath) ? '/home/yak/mcp-config.json' : null;
    }

    public function revokePlaceholder(Thread $thread): void
    {
        $this->placeholders->revoke($thread);
    }

    public function turn(Thread $thread, string $uuid, string $text, ?string $priority = null): void
    {
        $this->send($thread, $this->message('turn', $uuid, $text, $priority));
    }

    public function steer(Thread $thread, string $uuid, string $text, ?string $priority = null): void
    {
        $this->send($thread, $this->message('steer', $uuid, $text, $priority));
    }

    public function interrupt(Thread $thread): void
    {
        $this->send($thread, ['type' => 'interrupt']);
    }

    public function setModel(Thread $thread, string $model): void
    {
        $this->send($thread, ['type' => 'set_model', 'model' => $this->models->resolve($model)]);

        $thread->forceFill(['runner_model' => $model])->save();
    }

    public function end(Thread $thread): void
    {
        $this->send($thread, ['type' => 'end']);

        $thread->forceFill(['runner_state' => RunnerState::Stopping])->save();
    }

    public function kill(Thread $thread): void
    {
        $this->send($thread, ['type' => 'kill']);
    }

    public function toolResult(Thread $thread, string $callId, bool $isError, string $content): void
    {
        $this->send($thread, ['type' => 'tool_result', 'call_id' => $callId, 'is_error' => $isError, 'content' => $content]);
    }

    /**
     * @return array<string, mixed>
     */
    private function message(string $type, string $uuid, string $text, ?string $priority): array
    {
        return array_filter(
            ['type' => $type, 'uuid' => $uuid, 'text' => $text, 'priority' => $priority],
            fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function send(Thread $thread, array $command): void
    {
        $channel = $this->channel($thread);

        $this->streams->register($channel);
        $this->streams->command($channel, $command);
    }

    /**
     * The sandbox's bridge address, which the auth proxy binds a placeholder to.
     */
    private function containerIp(Thread $thread): string
    {
        return SandboxAddress::fromIpAddr($this->sandbox->exec($thread, SandboxAddress::COMMAND)->output());
    }
}
