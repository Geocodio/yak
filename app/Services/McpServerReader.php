<?php

namespace App\Services;

use App\ClaudeCli;
use App\DataTransferObjects\McpServer;
use App\Exceptions\ClaudeCliException;
use Illuminate\Support\Collection;

/**
 * Reads the MCP servers Yak agents can reach: Ansible-provisioned "deploy"
 * servers (read from the JSON file the deploy pipeline writes) and the
 * "user"/"plugin" servers the `claude` CLI itself tracks (via `claude mcp
 * list`, which also health-checks each one).
 */
class McpServerReader
{
    public function __construct(private readonly ClaudeCli $cli) {}

    /**
     * @return Collection<int, McpServer>
     */
    public function all(): Collection
    {
        return $this->deployServers()->concat($this->userServers());
    }

    /**
     * @return Collection<int, McpServer>
     */
    public function deployServers(): Collection
    {
        $path = config('yak.mcp_config_path');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return collect();
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! isset($data['mcpServers']) || ! is_array($data['mcpServers'])) {
            return collect();
        }

        /** @var array<string, array<string, mixed>> $servers */
        $servers = $data['mcpServers'];

        return collect($servers)
            ->map(function (array $config, string $name) {
                $transport = $this->deployTransport($config);
                $target = $this->deployTarget($config, $transport);

                return new McpServer(
                    name: $name,
                    displayName: $this->displayName($name),
                    target: $target,
                    transport: $transport,
                    source: 'deploy',
                    pluginName: null,
                    status: 'token',
                    detail: null,
                    canConnect: false,
                    canLogout: false,
                    canRemove: false,
                    loginCommand: $this->loginCommand($name),
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, McpServer>
     */
    public function userServers(): Collection
    {
        $result = $this->cli->exec('mcp list', timeout: 90);

        if (! $result->successful()) {
            $message = trim($result->errorOutput() ?: $result->output());

            throw new ClaudeCliException("claude mcp list failed: {$message}");
        }

        $lines = preg_split('/\R/u', $result->output()) ?: [];

        return collect($lines)
            ->map(fn (string $line) => $this->parseLine($line))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function deployTransport(array $config): string
    {
        $type = is_string($config['type'] ?? null) ? $config['type'] : null;

        if ($type !== null) {
            return in_array($type, ['http', 'sse', 'stdio'], true) ? $type : 'unknown';
        }

        return isset($config['command']) ? 'stdio' : (isset($config['url']) ? 'http' : 'unknown');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function deployTarget(array $config, string $transport): string
    {
        if ($transport === 'stdio') {
            $command = is_string($config['command'] ?? null) ? $config['command'] : '';
            $args = is_array($config['args'] ?? null) ? array_map('strval', $config['args']) : [];

            return trim($command . ' ' . implode(' ', $args));
        }

        return is_string($config['url'] ?? null) ? $config['url'] : '';
    }

    private function parseLine(string $line): ?McpServer
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $pattern = '/^(?<name>.+?): (?<target>.+?)(?: \((?<transport>HTTP|SSE|stdio)\))? - (?<mark>[✔!✘⏸]) (?<status>.+)$/u';

        if (preg_match($pattern, $line, $matches) !== 1) {
            return null;
        }

        $name = $matches['name'];
        $target = $matches['target'];
        $statusText = trim($matches['status']);

        $isPlugin = str_starts_with($name, 'plugin:');
        $pluginName = null;

        if ($isPlugin) {
            $segments = explode(':', $name, 3);
            $pluginName = $segments[1] ?? null;
        }

        $status = $this->mapStatus($matches['mark'], $statusText);
        $transportHint = ! empty($matches['transport']) ? $matches['transport'] : null;
        $transport = $this->userTransport($transportHint, $target);

        return new McpServer(
            name: $name,
            displayName: $this->displayName($name),
            target: $target,
            transport: $transport,
            source: $isPlugin ? 'plugin' : 'user',
            pluginName: $pluginName,
            status: $status,
            detail: $status === 'failed' ? $statusText : null,
            canConnect: $status === 'needs_auth',
            canLogout: $status === 'connected' && $transport !== 'stdio',
            canRemove: ! $isPlugin,
            loginCommand: $this->loginCommand($name),
        );
    }

    private function mapStatus(string $mark, string $statusText): string
    {
        return match (true) {
            $mark === '✔' => 'connected',
            str_contains($statusText, 'Needs authentication') => 'needs_auth',
            $mark === '✘' || str_contains($statusText, 'Failed to connect') => 'failed',
            $mark === '⏸' || str_contains($statusText, 'Pending approval') => 'pending_approval',
            default => 'unknown',
        };
    }

    private function userTransport(?string $hint, string $target): string
    {
        if ($hint !== null) {
            return mb_strtolower($hint);
        }

        return str_starts_with($target, 'http://') || str_starts_with($target, 'https://') ? 'http' : 'stdio';
    }

    private function displayName(string $name): string
    {
        if (! str_starts_with($name, 'plugin:')) {
            return $name;
        }

        $segments = explode(':', $name);

        return end($segments);
    }

    private function loginCommand(string $name): string
    {
        $arg = str_contains($name, ' ') ? '"' . str_replace('"', '\\"', $name) . '"' : $name;

        return "yak-mcp login {$arg}";
    }
}
