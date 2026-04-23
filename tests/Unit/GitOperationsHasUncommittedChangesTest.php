<?php

use App\GitOperations;
use App\Services\IncusSandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;

function fakeSandboxReturningPorcelain(string $stdout, int $exit = 0): IncusSandboxManager
{
    return new class($stdout, $exit) extends IncusSandboxManager
    {
        public string $lastCommand = '';

        public function __construct(private string $stdout, private int $exit) {}

        public function run(string $containerName, string $command, ?int $timeout = null, bool $asRoot = false, ?string $input = null): ProcessResult
        {
            $this->lastCommand = $command;

            return new FakeProcessResult(output: $this->stdout, exitCode: $this->exit);
        }
    };
}

test('returns false when git status is clean', function () {
    $sandbox = fakeSandboxReturningPorcelain('');

    $dirty = GitOperations::hasUncommittedChanges($sandbox, 'task-1', '/workspace');

    expect($dirty)->toBeFalse();
    expect($sandbox->lastCommand)->toContain('git status --porcelain');
});

test('returns true when git status shows modifications', function () {
    $sandbox = fakeSandboxReturningPorcelain(" M app/Foo.php\n?? new-file.txt\n");

    expect(GitOperations::hasUncommittedChanges($sandbox, 'task-1', '/workspace'))->toBeTrue();
});

test('returns true when only untracked files are present', function () {
    $sandbox = fakeSandboxReturningPorcelain("?? unstaged.txt\n");

    expect(GitOperations::hasUncommittedChanges($sandbox, 'task-1', '/workspace'))->toBeTrue();
});

test('throws when the git command exits non-zero', function () {
    $sandbox = fakeSandboxReturningPorcelain('fatal: not a git repository', exit: 128);

    expect(fn () => GitOperations::hasUncommittedChanges($sandbox, 'task-1', '/workspace'))
        ->toThrow(RuntimeException::class, 'hasUncommittedChanges failed');
});
