<?php

namespace App;

use App\Exceptions\ClaudeCliException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

/**
 * Thin wrapper around the host-side `claude` CLI used by the /skills dashboard
 * page to manage plugins and marketplaces under /home/yak/.claude. This is
 * orthogonal to the agent runner — the agent itself runs inside Incus
 * sandboxes via SandboxedAgentRunner and never goes through this class.
 */
class ClaudeCli
{
    public function exec(string $args, int $timeout = 60): ProcessResult
    {
        $command = sprintf(
            'env HOME=/home/yak CLAUDE_CONFIG_DIR=/home/yak/.claude claude %s',
            $args,
        );

        try {
            return Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException $e) {
            throw new ClaudeCliException("claude {$args} timed out after {$timeout}s", previous: $e);
        }
    }
}
