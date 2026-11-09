<?php

namespace App\Services\Threads;

use App\Enums\RunnerState;
use App\Models\ClaudeAccount;
use App\Models\Thread;

/**
 * Writes runner commands to a thread's broker stream. The stream carries the
 * thread's auth proxy placeholder, never a credential: the proxy swaps the
 * real token in on the host.
 */
class ThreadCommands
{
    private const SANDBOX_USER_ID = 1001;

    private const WORKING_DIRECTORY = '/workspace';

    public function __construct(private readonly ThreadStreams $streams, private readonly AuthProxyPlaceholders $placeholders) {}

    public function startRunner(Thread $thread, ClaudeAccount $account, ?string $resumeSessionId, string $appendSystemPrompt): void
    {
        $this->send($thread, [
            'type' => 'start_runner',
            'container' => $thread->sandbox_container,
            'uid' => self::SANDBOX_USER_ID,
            'cwd' => self::WORKING_DIRECTORY,
            'start' => [
                'type' => 'start',
                'model' => $thread->model,
                'appendSystemPrompt' => $appendSystemPrompt,
                'permissionMode' => 'bypassPermissions',
                'cwd' => self::WORKING_DIRECTORY,
                'additionalDirectories' => [],
                'mcpServers' => (object) $this->mcpServers(),
                'settingSources' => ['user', 'project', 'local'],
                'resume' => $resumeSessionId,
                'claudeConfigDir' => $thread->claude_config_dir,
                'placeholder' => $this->placeholders->issueForThread($thread, $account),
                'anthropicBaseUrl' => (string) config('yak.threads.auth_proxy_url'),
            ],
        ]);

        $thread->forceFill([
            'runner_state' => RunnerState::Starting,
            'runner_model' => $thread->model,
            'append_system_prompt' => $appendSystemPrompt,
        ])->save();
    }

    public function turn(Thread $thread, string $uuid, string $text): void
    {
        $this->send($thread, ['type' => 'turn', 'uuid' => $uuid, 'text' => $text]);
    }

    public function steer(Thread $thread, string $uuid, string $text, ?string $priority): void
    {
        $this->send($thread, array_filter(
            ['type' => 'steer', 'uuid' => $uuid, 'text' => $text, 'priority' => $priority],
            fn (mixed $value): bool => $value !== null,
        ));
    }

    public function interrupt(Thread $thread): void
    {
        $this->send($thread, ['type' => 'interrupt']);
    }

    public function setModel(Thread $thread, string $model): void
    {
        $this->send($thread, ['type' => 'set_model', 'model' => $model]);

        $thread->forceFill(['runner_model' => $model])->save();
    }

    public function end(Thread $thread): void
    {
        $this->send($thread, ['type' => 'end']);
    }

    public function kill(Thread $thread): void
    {
        $this->revokePlaceholder($thread);
        $this->send($thread, ['type' => 'kill']);
    }

    /**
     * The runner that held the thread's placeholder is gone; the proxy stops serving it.
     */
    public function revokePlaceholder(Thread $thread): void
    {
        $this->placeholders->revokeForThread($thread);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function send(Thread $thread, array $command): void
    {
        $this->streams->register($thread->channel());
        $this->streams->command($thread->channel(), $command);
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpServers(): array
    {
        $path = config('yak.mcp_config_path');

        if (! is_string($path) || ! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && is_array($data['mcpServers'] ?? null) ? $data['mcpServers'] : [];
    }
}
