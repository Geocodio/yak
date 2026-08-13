<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Verifies the shared Claude Max session is valid.
 *
 * The session token at /home/yak/.claude.json is mounted from the host
 * and pushed into every sandbox at create time. We probe it from the
 * yak app container (where the `claude` binary is also installed for
 * the /skills dashboard); if `claude auth status` succeeds here, every
 * sandbox that gets the same files will succeed too.
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
        $sessionFile = dirname($configDir) . '/.claude.json';

        if (! is_file($sessionFile)) {
            return HealthResult::error("Session token missing at {$sessionFile} — run `docker exec -it yak claude login`");
        }

        $this->sweepStaleOauthRefreshLock($configDir);

        $command = sprintf(
            'env HOME=%s CLAUDE_CONFIG_DIR=%s claude auth status',
            escapeshellarg(dirname($configDir)),
            escapeshellarg($configDir),
        );

        try {
            // 60s, not less: with an expired access token `claude auth status`
            // performs an OAuth refresh, and killing the CLI mid-refresh
            // orphans .oauth_refresh.lock (task 5434 got 401s from this).
            $result = Process::timeout(60)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out');
        }

        if ($result->successful()) {
            return HealthResult::ok('Authenticated');
        }

        return HealthResult::error('Claude session expired — run `docker exec -it yak claude login` to re-authenticate');
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
