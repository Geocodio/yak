<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Verifies the shared Claude Max session is usable by running a real
 * inference call, not `claude auth status` (which does not perform a
 * token refresh and only answers "is the host logged in").
 *
 * The shared config dir at `yak.sandbox.claude_config_source` is mounted
 * into every sandbox at create time. We probe it from the yak app
 * container (where the `claude` binary is also installed for the
 * /skills dashboard); if the probe succeeds here, every sandbox that
 * gets the same mount will succeed too. On failure, sets
 * self::UNUSABLE_CACHE_KEY so the HoldsForClaudeAuth job middleware can
 * hold tasks until the session is fixed.
 */
class ClaudeAuthCheck implements HealthCheck
{
    /**
     * A .oauth_refresh.lock older than this is an orphan (a real refresh
     * completes in seconds). Left in place it blocks every subsequent
     * refresh — on the host and in every sandbox that gets a copy of the
     * config dir — until someone deletes it by hand.
     */
    private const STALE_LOCK_SECONDS = 600;

    /**
     * Cache flag read by the HoldsForClaudeAuth job middleware.
     */
    public const UNUSABLE_CACHE_KEY = 'yak:claude-auth-unusable';

    public function id(): string
    {
        return 'claude-auth';
    }

    public function name(): string
    {
        return 'Claude Max Session';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $configDir = (string) config('yak.sandbox.claude_config_source', '/home/yak/.claude');

        $this->sweepStaleOauthRefreshLock($configDir);

        // A real inference call, not `claude auth status`: status does not
        // exercise a refresh and answers "is the host logged in", not "can a
        // task start". ANTHROPIC_API_KEY is unset because with it set the CLI
        // takes the billed API path and reports healthy while the
        // subscription session is dead.
        $command = sprintf(
            'env -u ANTHROPIC_API_KEY HOME=%s CLAUDE_CONFIG_DIR=%s claude --model claude-haiku-4-5 -p %s',
            escapeshellarg(dirname($configDir)),
            escapeshellarg($configDir),
            escapeshellarg('Reply with exactly: ok'),
        );

        try {
            $result = Process::timeout(120)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return $this->unusable('Timed out probing the Claude session');
        }

        if ($result->successful()) {
            Cache::forget(self::UNUSABLE_CACHE_KEY);

            return HealthResult::ok('Authenticated');
        }

        return $this->unusable('Claude session is not usable');
    }

    private function unusable(string $reason): HealthResult
    {
        Cache::put(self::UNUSABLE_CACHE_KEY, true, now()->addHours(24));

        return HealthResult::error($reason . ' — re-authenticate: ssh the host and run `yak-claude-login`, then type /login');
    }

    private function sweepStaleOauthRefreshLock(string $configDir): void
    {
        $lockDir = $configDir . '/.oauth_refresh.lock';

        if (! is_dir($lockDir)) {
            return;
        }

        $age = time() - (int) filemtime($lockDir);

        // A fresh lock likely belongs to an in-flight refresh; deleting it
        // could corrupt a rotation about to be persisted.
        if ($age < self::STALE_LOCK_SECONDS) {
            return;
        }

        File::deleteDirectory($lockDir);

        Log::channel('yak')->warning('Swept stale .oauth_refresh.lock', [
            'lock_dir' => $lockDir,
            'age_seconds' => $age,
        ]);
    }
}
