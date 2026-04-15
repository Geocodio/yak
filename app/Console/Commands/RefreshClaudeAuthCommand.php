<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Keep the shared Claude Max OAuth token alive.
 *
 * The host `/home/yak/.claude/.credentials.json` access token expires
 * every ~8 hours. Claude CLI refreshes it lazily on the next API call,
 * but if nothing invokes claude on the host between one expiry and the
 * next task (sandboxes never write back), the host token goes stale and
 * every sandbox that starts after expiry hits a 401.
 *
 * Running `claude -p` here forces an actual inference call, which walks
 * the refresh code path whenever the access token is near/past expiry.
 * If the refresh token itself has been invalidated (server-side revoke),
 * this command surfaces it in the yak log before a user-facing task
 * fails.
 */
#[Signature('yak:refresh-claude-auth')]
#[Description('Keep the shared Claude Max OAuth token refreshed on the host')]
class RefreshClaudeAuthCommand extends Command
{
    public function handle(): int
    {
        $configDir = (string) config('yak.sandbox.claude_config_source', '/home/yak/.claude');

        $command = sprintf(
            'env HOME=%s CLAUDE_CONFIG_DIR=%s claude --model claude-haiku-4-5 -p %s',
            escapeshellarg(dirname($configDir)),
            escapeshellarg($configDir),
            escapeshellarg('Reply with exactly: ok'),
        );

        try {
            $result = Process::timeout(30)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            Log::channel('yak')->error('Claude auth refresh timed out');
            $this->components->error('Claude auth refresh timed out');

            return self::FAILURE;
        }

        if (! $result->successful()) {
            Log::channel('yak')->error('Claude auth refresh failed', [
                'exit_code' => $result->exitCode(),
                'output' => trim($result->output()),
                'error_output' => trim($result->errorOutput()),
            ]);

            $this->components->error('Claude auth refresh failed — interactive re-login may be required');

            return self::FAILURE;
        }

        $this->components->info('Claude auth refreshed');

        return self::SUCCESS;
    }
}
