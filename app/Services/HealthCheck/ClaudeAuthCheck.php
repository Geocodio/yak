<?php

namespace App\Services\HealthCheck;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
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

        $command = sprintf(
            'env HOME=%s CLAUDE_CONFIG_DIR=%s claude auth status',
            escapeshellarg(dirname($configDir)),
            escapeshellarg($configDir),
        );

        try {
            $result = Process::timeout(15)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            return HealthResult::error('Timed out');
        }

        if ($result->successful()) {
            return HealthResult::ok('Authenticated');
        }

        return HealthResult::error('Claude session expired — run `docker exec -it yak claude login` to re-authenticate');
    }
}
