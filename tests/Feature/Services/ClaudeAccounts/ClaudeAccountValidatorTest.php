<?php

use App\Enums\ClaudeAccountKind;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function fakeClaude(string $output = '{"is_error":false,"result":"OK"}', int $exitCode = 0): void
{
    Process::fake(['*' => Process::result(output: $output, exitCode: $exitCode)]);
}

it('sends only a placeholder and the proxy address, never the secret', function () {
    fakeClaude();
    config()->set('yak.threads.auth_proxy_url', 'http://10.100.0.1:8787');

    $validation = app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::SetupToken, 'sk-ant-oat01-secret');

    expect($validation->valid)->toBeTrue();
    Process::assertRan(function (PendingProcess $process) {
        $command = implode(' ', $process->command);

        return ! str_contains($command, 'sk-ant-oat01-secret')
            && ! str_contains(json_encode($process->environment), 'sk-ant-oat01-secret')
            && str_starts_with($process->environment['CLAUDE_CODE_OAUTH_TOKEN'], 'yakph-')
            && $process->environment['ANTHROPIC_BASE_URL'] === 'http://10.100.0.1:8787'
            && $process->environment['CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC'] === '1'
            && ($process->environment['ANTHROPIC_API_KEY'] ?? false) === false
            && $process->environment['HOME'] !== getenv('HOME')
            && str_contains($process->environment['CLAUDE_CONFIG_DIR'], 'yak-account-');
    });
});

it('lets the proxy resolve the secret only while the validation runs', function () {
    $resolved = [];
    Process::fake(function (PendingProcess $process) use (&$resolved) {
        $resolved[] = app(AuthProxyPlaceholders::class)->resolve($process->environment['CLAUDE_CODE_OAUTH_TOKEN']);
        $placeholder = $process->environment['CLAUDE_CODE_OAUTH_TOKEN'];

        return Process::result(output: '{"is_error":false,"result":"OK"}');
    });

    app(ClaudeAccountValidator::class)->validate(ClaudeAccountKind::ApiKey, 'sk-ant-api03-key');

    expect($resolved[0])->toBe(['type' => 'api_key', 'value' => 'sk-ant-api03-key', 'host' => true]);
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
