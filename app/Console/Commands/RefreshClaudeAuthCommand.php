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

        // Unset ANTHROPIC_API_KEY so the CLI is forced through the
        // OAuth (Max subscription) path. With the key set, claude
        // short-circuits and bills the API — which makes this command
        // report "refreshed" even when OAuth is dead, silently masking
        // exactly the failure mode sandboxes hit (they don't inherit
        // ANTHROPIC_API_KEY, so they have no fallback). Burned us once
        // already; surfacing OAuth breakage here is the whole point.
        $command = sprintf(
            'env -u ANTHROPIC_API_KEY HOME=%s CLAUDE_CONFIG_DIR=%s claude --model claude-haiku-4-5 -p %s',
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
