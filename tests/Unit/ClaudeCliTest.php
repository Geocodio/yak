<?php

use App\ClaudeCli;
use App\Exceptions\ClaudeCliException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\TestCase;

uses(TestCase::class);

it('wraps claude commands as the yak user with HOME set', function () {
    Process::fake([
        '*' => Process::result(output: '', exitCode: 0),
    ]);

    app(ClaudeCli::class)->exec('plugins list');

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'sudo runuser -u yak -- env HOME=/home/yak bash -c')
            && str_contains($process->command, "'claude plugins list'");
    });
});

it('applies the configured timeout', function () {
    Process::fake(['*' => Process::result()]);

    app(ClaudeCli::class)->exec('plugins list', timeout: 90);

    Process::assertRan(function ($process) {
        return $process->timeout === 90;
    });
});

it('wraps Symfony timeout exceptions as ClaudeCliException', function () {
    Process::fake([
        '*' => function () {
            throw new ProcessTimedOutException(
                Symfony\Component\Process\Process::fromShellCommandline('true'),
                ProcessTimedOutException::TYPE_GENERAL,
            );
        },
    ]);

    expect(fn () => app(ClaudeCli::class)->exec('plugins list'))
        ->toThrow(ClaudeCliException::class);
});

it('includes extra env vars in the wrapped command', function () {
    $wrapped = app(ClaudeCli::class)->buildWrappedCommand('echo hi', ['FOO' => 'bar baz']);
    expect($wrapped)->toContain("HOME=/home/yak FOO='bar baz'")
        ->and($wrapped)->toContain("bash -c 'echo hi'");
});
