<?php

namespace Tests\Support;

use App\Models\Thread;
use App\Services\Threads\FakeSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * A fake sandbox with an in-memory filesystem per thread, answering the shell
 * commands SessionSync issues (find, tail | head, sha256sum, mkdir && cat, chown).
 */
class FilesystemSandboxManager extends FakeSandboxManager
{
    /** @var array<int, array<string, string>> absolute path => bytes, by thread id */
    public array $disks = [];

    /** @var list<string> */
    public array $commands = [];

    /** @var array<string, string> command substring => error output */
    public array $failures = [];

    public function exec(Thread $thread, array $command, ?string $stdin = null, array $env = []): ProcessResult
    {
        $shell = (string) end($command);
        $this->commands[] = $shell;
        $files = &$this->disks[$thread->id];
        $files ??= [];

        foreach ($this->failures as $substring => $error) {
            if (str_contains($shell, $substring)) {
                return $this->result('', $error, 1);
            }
        }

        if (preg_match("/^find '(.+)' -type f/", $shell, $matches) === 1) {
            $prefix = $matches[1] . '/';
            $lines = '';
            foreach ($files as $path => $bytes) {
                if (str_starts_with($path, $prefix)) {
                    $lines .= strlen($bytes) . ' ' . substr($path, strlen($prefix)) . "\n";
                }
            }

            return $this->result($lines);
        }

        if (preg_match("/^tail -c \\+(\\d+) '(.+)' \\| head -c (\\d+)$/", $shell, $matches) === 1) {
            return $this->result(substr($files[$matches[2]] ?? '', (int) $matches[1] - 1, (int) $matches[3]));
        }

        if (preg_match("/^sha256sum '(.+)'$/", $shell, $matches) === 1) {
            return $this->result(hash('sha256', $files[$matches[1]] ?? '') . "  {$matches[1]}\n");
        }

        if (preg_match("/&& cat > '(.+)'$/", $shell, $matches) === 1) {
            $files[$matches[1]] = (string) $stdin;
        }

        return $this->result();
    }

    /**
     * Process::result() normalizes output to end in exactly one newline, which
     * would change file bytes, so the result carries the bytes untouched.
     */
    private function result(string $output = '', string $errorOutput = '', int $exitCode = 0): ProcessResult
    {
        return new class($output, $errorOutput, $exitCode) implements ProcessResult
        {
            public function __construct(private string $output, private string $errorOutput, private int $exitCode) {}

            public function command()
            {
                return '';
            }

            public function successful()
            {
                return $this->exitCode === 0;
            }

            public function failed()
            {
                return $this->exitCode !== 0;
            }

            public function exitCode()
            {
                return $this->exitCode;
            }

            public function output()
            {
                return $this->output;
            }

            public function seeInOutput(string $output)
            {
                return str_contains($this->output, $output);
            }

            public function errorOutput()
            {
                return $this->errorOutput;
            }

            public function seeInErrorOutput(string $output)
            {
                return str_contains($this->errorOutput, $output);
            }

            public function throw(?callable $callback = null)
            {
                return $this;
            }

            public function throwIf(bool $condition, ?callable $callback = null)
            {
                return $this;
            }
        };
    }
}
