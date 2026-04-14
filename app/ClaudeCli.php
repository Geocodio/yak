<?php

namespace App;

use App\Exceptions\ClaudeCliException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class ClaudeCli
{
    public function exec(string $args, int $timeout = 60): ProcessResult
    {
        $command = $this->buildWrappedCommand('claude ' . $args);

        try {
            return Process::timeout($timeout)->run($command);
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException $e) {
            throw new ClaudeCliException("claude {$args} timed out after {$timeout}s", previous: $e);
        }
    }

    /**
     * Build a shell command that runs $innerCommand as the yak user with
     * HOME pre-set and any additional environment variables supplied. Kept
     * public so other call sites (e.g. ClaudeCodeRunner) can share the
     * exact same env contract as exec().
     *
     * @param  array<string, string>  $extraEnv
     */
    public function buildWrappedCommand(string $innerCommand, array $extraEnv = []): string
    {
        $envParts = ['HOME=/home/yak'];

        foreach ($extraEnv as $name => $value) {
            $envParts[] = sprintf('%s=%s', $name, escapeshellarg((string) $value));
        }

        return sprintf(
            'sudo runuser -u yak -- env %s bash -c %s',
            implode(' ', $envParts),
            escapeshellarg($innerCommand),
        );
    }
}
