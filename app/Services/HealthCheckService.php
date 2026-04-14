<?php

namespace App\Services;

use App\Channel;
use App\Enums\TaskStatus;
use App\Models\Repository;
use App\Models\YakTask;
use Carbon\Carbon;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class HealthCheckService
{
    private const CACHE_KEY = 'health:results';

    private const CACHE_TTL_SECONDS = 90;

    /**
     * @return array{healthy: bool, detail: string}
     */
    public function checkQueueWorker(): array
    {
        try {
            $result = Process::timeout(5)->run('pgrep -f "artisan queue:work"');
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return ['healthy' => false, 'detail' => 'Timed out checking worker'];
        }

        if ($result->successful() && trim($result->output()) !== '') {
            $pid = (int) trim(explode("\n", trim($result->output()))[0]);

            return ['healthy' => true, 'detail' => "Running, PID {$pid}"];
        }

        return ['healthy' => false, 'detail' => 'Not running'];
    }

    /**
     * @return array{healthy: bool, detail: string}
     */
    public function checkLastTaskCompleted(): array
    {
        $task = YakTask::query()
            ->whereIn('status', [TaskStatus::Success->value, TaskStatus::Failed->value])
            ->latest('updated_at')
            ->first();

        if (! $task) {
            return ['healthy' => true, 'detail' => 'No completed tasks yet'];
        }

        $ago = Carbon::parse($task->updated_at)->diffForHumans();
        $label = "Task #{$task->id}";
        if ($task->external_id) {
            $label .= " — {$task->external_id}";
        }

        return ['healthy' => true, 'detail' => "{$ago} ({$label})"];
    }

    /**
     * @return array{healthy: bool, detail: string}
     */
    public function checkRepositories(): array
    {
        $repos = Repository::where('is_active', true)->get();

        if ($repos->isEmpty()) {
            return ['healthy' => true, 'detail' => 'No active repositories'];
        }

        $total = $repos->count();
        $fetchable = 0;
        $failures = [];

        foreach ($repos as $repo) {
            try {
                $result = Process::path($repo->path)
                    ->timeout(10)
                    ->run('git ls-remote --exit-code origin HEAD');
            } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
                $failures[] = $repo->slug;

                continue;
            }

            if ($result->successful()) {
                $fetchable++;
            } else {
                $failures[] = $repo->slug;
            }
        }

        if ($fetchable === $total) {
            return ['healthy' => true, 'detail' => "{$fetchable}/{$total} active repositories OK"];
        }

        return [
            'healthy' => false,
            'detail' => "{$fetchable}/{$total} OK — failed: " . implode(', ', $failures),
        ];
    }

    /**
     * @return array{healthy: bool, detail: string}
     */
    public function checkClaudeCli(): array
    {
        try {
            $result = Process::timeout(15)->run('claude --version');
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return ['healthy' => false, 'detail' => 'Timed out'];
        }

        if ($result->successful()) {
            $version = trim($result->output());

            return ['healthy' => true, 'detail' => "Responding, {$version}"];
        }

        return ['healthy' => false, 'detail' => 'Not responding'];
    }

    /**
     * @return array{healthy: bool, detail: string}
     */
    public function checkClaudeAuth(): array
    {
        try {
            $result = Process::timeout(15)->run('claude auth status');
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return ['healthy' => false, 'detail' => 'Timed out'];
        }

        if ($result->successful()) {
            return ['healthy' => true, 'detail' => 'Authenticated'];
        }

        return ['healthy' => false, 'detail' => 'Claude CLI not authenticated — run `claude login` to re-authenticate'];
    }

    /**
     * @return array{healthy: bool, detail: string}
     */
    public function checkMcpServers(): array
    {
        $configPath = config('yak.mcp_config_path');

        if (! $configPath || ! file_exists($configPath)) {
            return ['healthy' => true, 'detail' => 'No MCP config found'];
        }

        $config = json_decode((string) file_get_contents($configPath), true);
        /** @var array<string, array<string, mixed>> $servers */
        $servers = $config['mcpServers'] ?? [];

        $enabledChannels = $this->enabledChannelNames();
        $relevant = [];

        foreach ($servers as $name => $serverConfig) {
            foreach ($enabledChannels as $channel) {
                if (stripos($name, $channel) !== false) {
                    $relevant[$name] = $serverConfig;
                }
            }
        }

        if (empty($relevant)) {
            return ['healthy' => true, 'detail' => 'No channel MCP servers configured'];
        }

        $total = count($relevant);
        $names = implode(', ', array_map('ucfirst', array_keys($relevant)));

        return ['healthy' => true, 'detail' => "{$total}/{$total} configured ({$names})"];
    }

    /**
     * Check if any webhook signature verifications have failed recently.
     *
     * @return array{healthy: bool, detail: string}
     */
    public function checkWebhookSignatures(): array
    {
        $channels = ['SlackWebhookController', 'LinearWebhookController', 'SentryWebhookController', 'GitHubWebhookController', 'GitHubCIWebhookController', 'DroneCIWebhookController'];
        $failures = [];

        foreach ($channels as $controller) {
            $count = (int) Cache::get("webhook-signature-failures:{$controller}", 0);
            if ($count > 0) {
                $name = str_replace('WebhookController', '', $controller);
                $failures[] = "{$name} ({$count})";
            }
        }

        if (empty($failures)) {
            return ['healthy' => true, 'detail' => 'No rejected webhooks'];
        }

        return [
            'healthy' => false,
            'detail' => 'Rejected webhooks — check signing secrets: ' . implode(', ', $failures),
        ];
    }

    /**
     * Return cached results when available, otherwise run all checks and cache the outcome.
     *
     * @return list<array{name: string, healthy: bool, detail: string, checked_at: Carbon}>
     */
    public function runAll(): array
    {
        /** @var list<array{name: string, healthy: bool, detail: string, checked_at: Carbon}> */
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->runAllFresh(),
        );
    }

    /**
     * Force a fresh run of all checks, bypassing and refreshing the cache.
     *
     * @return list<array{name: string, healthy: bool, detail: string, checked_at: Carbon}>
     */
    public function runAllFresh(): array
    {
        $now = Carbon::now();
        $checks = [
            ['name' => 'Queue Worker', 'method' => 'checkQueueWorker'],
            ['name' => 'Last Task Completed', 'method' => 'checkLastTaskCompleted'],
            ['name' => 'Repositories Fetchable', 'method' => 'checkRepositories'],
            ['name' => 'Claude CLI', 'method' => 'checkClaudeCli'],
            ['name' => 'Claude CLI Auth', 'method' => 'checkClaudeAuth'],
            ['name' => 'MCP Servers', 'method' => 'checkMcpServers'],
            ['name' => 'Webhook Signatures', 'method' => 'checkWebhookSignatures'],
        ];

        $results = [];
        foreach ($checks as $check) {
            /** @var array{healthy: bool, detail: string} $result */
            $result = $this->{$check['method']}();
            $results[] = [
                'name' => $check['name'],
                'healthy' => $result['healthy'],
                'detail' => $result['detail'],
                'checked_at' => $now,
            ];
        }

        Cache::put(self::CACHE_KEY, $results, self::CACHE_TTL_SECONDS);

        return $results;
    }

    /**
     * @return list<string>
     */
    private function enabledChannelNames(): array
    {
        $names = [];
        /** @var array<string, mixed> $channels */
        $channels = config('yak.channels') ?? [];

        foreach (array_keys($channels) as $name) {
            if ((new Channel($name))->enabled()) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
