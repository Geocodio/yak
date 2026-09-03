<?php

namespace App\Http\Controllers\Settings;

use App\ClaudeCli;
use App\DataTransferObjects\McpServer;
use App\Exceptions\ClaudeCliException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreMcpServerRequest;
use App\Services\McpServerReader;
use App\Support\Docs;
use App\Support\McpLoginSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class McpServerController extends Controller
{
    public function __construct(
        private readonly ClaudeCli $cli,
        private readonly McpServerReader $reader,
    ) {}

    public function index(Request $request): Response
    {
        $partialData = (string) $request->header('X-Inertia-Partial-Data', '');
        $requestedProps = $partialData !== '' ? explode(',', $partialData) : [];
        $resolvingServers = in_array('servers', $requestedProps, true);

        return Inertia::render('Settings/McpServers', [
            'servers' => Inertia::defer(fn () => $this->serversData(), 'servers'),
            'loginSessions' => fn () => $this->loginSessionsData(),
            'checkedAgo' => $resolvingServers ? 'just now' : null,
            'sshHost' => config('yak.ssh_host'),
            'docsUrl' => Docs::url('prompting.mcp'),
        ]);
    }

    public function store(StoreMcpServerRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        return $this->runSafely(function () use ($validated) {
            $name = (string) $validated['name'];
            $transport = (string) $validated['transport'];
            $target = trim((string) $validated['target']);
            $headers = (string) ($validated['headers'] ?? '');

            $tokens = ['mcp', 'add', '--scope', 'user', '--transport', $transport];

            foreach ($this->parseHeaderLines($headers, $transport) as [$key, $value]) {
                if ($transport === 'stdio') {
                    $tokens[] = '-e';
                    $tokens[] = escapeshellarg("{$key}={$value}");
                } else {
                    $tokens[] = '-H';
                    $tokens[] = escapeshellarg("{$key}: {$value}");
                }
            }

            $tokens[] = escapeshellarg($name);

            if ($transport === 'stdio') {
                $tokens[] = '--';

                foreach (preg_split('/\s+/', $target, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                    $tokens[] = escapeshellarg($part);
                }
            } else {
                $tokens[] = escapeshellarg($target);
            }

            $this->runCli(implode(' ', $tokens));

            return "Added {$name}.";
        });
    }

    public function destroy(string $name): RedirectResponse
    {
        return $this->runSafely(function () use ($name) {
            $server = $this->reader->all()->first(fn (McpServer $s) => $s->name === $name);

            if ($server === null) {
                throw new ClaudeCliException("{$name} was not found.");
            }

            if ($server->source === 'deploy') {
                throw new ClaudeCliException("{$name} is managed by Ansible.");
            }

            if ($server->source === 'plugin') {
                throw new ClaudeCliException("{$name} comes from the {$server->pluginName} plugin; remove it from Skills.");
            }

            // Best-effort: a server may not be logged in, and `mcp remove`
            // below is what actually matters for this action to succeed.
            $this->cli->exec('mcp logout ' . escapeshellarg($name));

            $this->runCli('mcp remove --scope user ' . escapeshellarg($name));

            return "Removed {$name}.";
        });
    }

    public function logout(string $name): RedirectResponse
    {
        return $this->runSafely(function () use ($name) {
            $this->runCli('mcp logout ' . escapeshellarg($name));

            return "Logged out of {$name}.";
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serversData(): array
    {
        $deploy = $this->reader->deployServers();

        try {
            $user = $this->reader->userServers();
        } catch (ClaudeCliException $e) {
            session()->flash('error', $e->getMessage());
            $user = collect();
        }

        return $deploy->concat($user)->map(fn (McpServer $s) => $s->toArray())->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loginSessionsData(): array
    {
        return collect(McpLoginSession::activeNames())
            ->mapWithKeys(fn (string $name) => [$name => McpLoginSession::find($name)])
            ->filter()
            ->map(fn (McpLoginSession $session) => $session->toArray())
            ->all();
    }

    /**
     * Splits the headers/env textarea into key/value pairs. `Key: value`
     * lines for http/sse transports, `KEY=value` lines for stdio (passed
     * to the CLI as -e env vars instead of -H headers).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function parseHeaderLines(string $raw, string $transport): array
    {
        $delimiter = $transport === 'stdio' ? '=' : ':';

        $pairs = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $pos = mb_strpos($line, $delimiter);

            if ($pos === false) {
                continue;
            }

            $key = trim(mb_substr($line, 0, $pos));
            $value = trim(mb_substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            $pairs[] = [$key, $value];
        }

        return $pairs;
    }

    private function runCli(string $args, int $timeout = 60): void
    {
        $result = $this->cli->exec($args, $timeout);

        if (! $result->successful()) {
            $message = trim($result->errorOutput() ?: $result->output());

            throw new ClaudeCliException("claude {$args} failed: {$message}");
        }
    }

    private function runSafely(\Closure $action): RedirectResponse
    {
        try {
            $message = $action();
        } catch (ClaudeCliException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }
}
