<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed record of an in-progress `claude mcp login` run, driven by
 * McpLoginController and McpLoginJob. Lives entirely in the cache (not the
 * database) so a login started on one request and polled/finished across
 * several others just needs a shared cache store — see
 * config('yak.mcp_config_path') sibling behaviour for why this whole
 * feature stays filesystem/CLI-backed rather than modeled.
 *
 * A small secondary index (`mcp-login:index`) tracks which server names
 * currently have a session worth showing on the Settings > MCP servers
 * page, since `servers` is an Inertia::defer'd prop and `loginSessions`
 * is not — the index lets the controller build `loginSessions` without
 * scanning every possible server name. Terminal sessions (succeeded,
 * failed, expired, cancelled) stay in the index for two minutes after
 * they finish so the UI has a chance to show the outcome, then age out.
 */
class McpLoginSession
{
    private const TTL_MINUTES = 15;

    private const SESSION_DURATION_MINUTES = 10;

    private const INDEX_TERMINAL_GRACE_MINUTES = 2;

    /** @var array<int, string> */
    public const TERMINAL_STATUSES = ['succeeded', 'failed', 'expired', 'cancelled'];

    /** @var array<int, string> */
    public const ACTIVE_STATUSES = ['starting', 'awaiting_redirect', 'finishing'];

    public function __construct(
        public string $server,
        public string $status,
        public ?string $authorizationUrl,
        public ?string $error,
        public Carbon $startedAt,
        public Carbon $expiresAt,
        public ?string $redirectUrl = null,
    ) {}

    public static function find(string $name): ?self
    {
        /** @var array<string, mixed>|null $data */
        $data = Cache::get(self::cacheKey($name));

        if (! is_array($data)) {
            return null;
        }

        return self::fromArray($data);
    }

    public static function start(string $name): self
    {
        $now = Carbon::now();

        $session = new self(
            server: $name,
            status: 'starting',
            authorizationUrl: null,
            error: null,
            startedAt: $now,
            expiresAt: $now->clone()->addMinutes(self::SESSION_DURATION_MINUTES),
        );

        $session->save();

        return $session;
    }

    public function save(): void
    {
        Cache::put(self::cacheKey($this->server), $this->toStorageArray(), now()->addMinutes(self::TTL_MINUTES));

        self::updateIndex($this->server, $this->status);
    }

    public function delete(): void
    {
        Cache::forget(self::cacheKey($this->server));
        self::removeFromIndex($this->server);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Server names with a session worth showing on the page, pruning any
     * whose terminal grace period has elapsed.
     *
     * @return array<int, string>
     */
    public static function activeNames(): array
    {
        /** @var array<string, ?string> $index */
        $index = Cache::get(self::indexKey(), []);

        $changed = false;
        $names = [];

        foreach ($index as $name => $terminalAt) {
            if ($terminalAt !== null && Carbon::parse($terminalAt)->lt(now()->subMinutes(self::INDEX_TERMINAL_GRACE_MINUTES))) {
                unset($index[$name]);
                $changed = true;

                continue;
            }

            $names[] = $name;
        }

        if ($changed) {
            Cache::put(self::indexKey(), $index, now()->addMinutes(self::TTL_MINUTES));
        }

        return $names;
    }

    /**
     * @return array{server: string, status: string, authorizationUrl: ?string, error: ?string, startedAt: string, expiresAt: string}
     */
    public function toArray(): array
    {
        return [
            'server' => $this->server,
            'status' => $this->status,
            'authorizationUrl' => $this->authorizationUrl,
            'error' => $this->error,
            'startedAt' => $this->startedAt->toIso8601String(),
            'expiresAt' => $this->expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toStorageArray(): array
    {
        return [
            'server' => $this->server,
            'status' => $this->status,
            'authorizationUrl' => $this->authorizationUrl,
            'error' => $this->error,
            'startedAt' => $this->startedAt->toIso8601String(),
            'expiresAt' => $this->expiresAt->toIso8601String(),
            'redirectUrl' => $this->redirectUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromArray(array $data): self
    {
        return new self(
            server: (string) $data['server'],
            status: (string) $data['status'],
            authorizationUrl: isset($data['authorizationUrl']) ? (string) $data['authorizationUrl'] : null,
            error: isset($data['error']) ? (string) $data['error'] : null,
            startedAt: Carbon::parse((string) $data['startedAt']),
            expiresAt: Carbon::parse((string) $data['expiresAt']),
            redirectUrl: isset($data['redirectUrl']) ? (string) $data['redirectUrl'] : null,
        );
    }

    private static function updateIndex(string $name, string $status): void
    {
        /** @var array<string, ?string> $index */
        $index = Cache::get(self::indexKey(), []);

        $index[$name] = in_array($status, self::TERMINAL_STATUSES, true) ? now()->toIso8601String() : null;

        Cache::put(self::indexKey(), $index, now()->addMinutes(self::TTL_MINUTES));
    }

    private static function removeFromIndex(string $name): void
    {
        /** @var array<string, ?string> $index */
        $index = Cache::get(self::indexKey(), []);

        unset($index[$name]);

        Cache::put(self::indexKey(), $index, now()->addMinutes(self::TTL_MINUTES));
    }

    private static function cacheKey(string $name): string
    {
        return "mcp-login:{$name}";
    }

    private static function indexKey(): string
    {
        return 'mcp-login:index';
    }
}
