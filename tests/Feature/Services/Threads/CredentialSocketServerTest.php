<?php

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\CredentialSocketServer;

beforeEach(function () {
    $this->path = sys_get_temp_dir() . '/yk' . getmypid() . '.sock';
    $this->account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-secret-value', 'kind' => ClaudeAccountKind::SetupToken]);
});

afterEach(function () {
    @unlink($this->path);
});

function redeemOverSocket(CredentialSocketServer $server, string $path, string $placeholder): array
{
    $client = stream_socket_client('unix://' . $path, $errorCode, $errorMessage, 2);
    fwrite($client, json_encode(['placeholder' => $placeholder]) . "\n");
    $server->serve(500_000);
    $reply = json_decode((string) fgets($client), true);
    fclose($client);

    return $reply;
}

it('returns the credential and its container to the allowed peer until the placeholder is revoked', function () {
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => 4242);
    $server->listen($this->path, 4242);
    $thread = Thread::factory()->create(['sandbox_container' => 'yak-thread-3']);
    $placeholder = app(AuthProxyPlaceholders::class)->issueForThread($thread, $this->account);

    expect(redeemOverSocket($server, $this->path, $placeholder))->toBe(['type' => 'oauth_token', 'value' => 'sk-ant-secret-value', 'container' => 'yak-thread-3'])
        ->and(redeemOverSocket($server, $this->path, $placeholder))->toHaveKey('value');

    app(AuthProxyPlaceholders::class)->revokeForThread($thread);

    expect(redeemOverSocket($server, $this->path, $placeholder))->toHaveKey('error');

    $server->close();
});

it('refuses a peer with another uid and reveals nothing', function () {
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => 1000);
    $server->listen($this->path, 4242);
    $thread = Thread::factory()->create(['sandbox_container' => 'yak-thread-3']);
    $placeholder = app(AuthProxyPlaceholders::class)->issueForThread($thread, $this->account);

    expect(redeemOverSocket($server, $this->path, $placeholder))->toBe(['error' => 'forbidden']);

    $server->close();
});

it('refuses a peer whose uid the kernel did not report', function () {
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => null);
    $server->listen($this->path, 4242);

    expect(redeemOverSocket($server, $this->path, 'x'))->toBe(['error' => 'forbidden']);

    $server->close();
});

it('makes the socket file readable and writable by its group only', function () {
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => 4242);
    $server->listen($this->path, 4242);

    expect(substr(sprintf('%o', fileperms($this->path)), -4))->toBe('0660');

    $server->close();
});
