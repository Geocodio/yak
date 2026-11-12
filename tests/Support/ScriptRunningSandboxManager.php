<?php

namespace Tests\Support;

use App\Models\Thread;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/** Runs stdin scripts for real and points github.com clone URLs at local bare repositories. */
class ScriptRunningSandboxManager extends LocalGitSandboxManager
{
    /** @var array<string, string> */
    public array $remotes = [];

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        if ($stdin === null || $command !== ['bash', '-s']) {
            return parent::exec($thread, $command, $stdin, $env);
        }

        $this->calls[] = ['operation' => 'exec', 'threadId' => $thread->id, 'arguments' => ['command' => $command, 'stdin' => $stdin, 'env' => $env]];

        $config = ['GIT_CONFIG_COUNT' => (string) count($this->remotes)];
        $index = 0;
        foreach ($this->remotes as $url => $path) {
            $config["GIT_CONFIG_KEY_{$index}"] = "url.{$path}.insteadOf";
            $config["GIT_CONFIG_VALUE_{$index}"] = $url;
            $index++;
        }

        return Process::env([...$config, 'GIT_TERMINAL_PROMPT' => '0'])->input($stdin)->run($command);
    }
}
