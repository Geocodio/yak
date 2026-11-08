<?php

use App\Enums\ClaudeAccountKind;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function fakeClaude(string $output = '{"is_error":false,"result":"OK"}', int $exitCode = 0): void
{
    Process::fake(['*' => Process::result(output: $output, exitCode: $exitCode)]);
}

it('passes the secret only through the environment', function () {
    fakeClaude();

    $validation = app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::SetupToken, 'sk-ant-oat01-secret');

    expect($validation->valid)->toBeTrue();
    Process::assertRan(function (PendingProcess $process) {
        $command = implode(' ', $process->command);

        return ! str_contains($command, 'sk-ant-oat01-secret')
            && ! str_contains($command, 'ANTHROPIC_API_KEY=')
            && $process->environment['CLAUDE_CODE_OAUTH_TOKEN'] === 'sk-ant-oat01-secret'
            && ($process->environment['ANTHROPIC_API_KEY'] ?? false) === false
            && $process->environment['HOME'] !== getenv('HOME')
            && str_contains($process->environment['CLAUDE_CONFIG_DIR'], 'yak-account-');
    });
});

it('sends an api key as ANTHROPIC_API_KEY', function () {
    fakeClaude();

    app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::ApiKey, 'sk-ant-api03-key');

    Process::assertRan(fn (PendingProcess $process) => $process->environment['ANTHROPIC_API_KEY'] === 'sk-ant-api03-key'
        && ($process->environment['CLAUDE_CODE_OAUTH_TOKEN'] ?? false) === false);
});

it('reports invalid on a non-zero exit without echoing the secret', function () {
    fakeClaude('Invalid sk-ant-oat01-secret', 1);

    $validation = app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::SetupToken, 'sk-ant-oat01-secret');

    expect($validation->valid)->toBeFalse()
        ->and($validation->error)->not->toContain('sk-ant-oat01-secret');
});

it('reports invalid when the result is an error', function () {
    fakeClaude('{"is_error":true,"result":"Invalid API key"}');

    $validation = app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::ApiKey, 'x');

    expect($validation->valid)->toBeFalse()->and($validation->error)->toBe('Invalid API key');
});

it('removes its temp config directories', function () {
    fakeClaude();
    $directories = [];
    Process::fake(function (PendingProcess $process) use (&$directories) {
        $directories = [$process->environment['HOME'], $process->environment['CLAUDE_CONFIG_DIR']];
        expect($directories[0])->toBeDirectory();

        return Process::result(output: '{"is_error":false}');
    });

    app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::SetupToken, 'x');

    expect($directories)->toHaveCount(2)
        ->and($directories[0])->not->toBeDirectory()
        ->and($directories[1])->not->toBeDirectory();
});
