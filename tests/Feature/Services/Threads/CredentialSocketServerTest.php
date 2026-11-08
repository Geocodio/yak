<?php

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Services\Threads\CredentialReferences;
use App\Services\Threads\CredentialSocketServer;

beforeEach(function () {
    $this->path = sys_get_temp_dir() . '/yk' . getmypid() . '.sock';
    $this->account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-secret-value', 'kind' => ClaudeAccountKind::SetupToken]);
});

afterEach(function () {
    @unlink($this->path);
});

function redeemOverSocket(CredentialSocketServer $server, string $path, string $reference): array
{
    $client = stream_socket_client('unix://' . $path, $errorCode, $errorMessage, 2);
    fwrite($client, json_encode(['reference' => $reference]) . "\n");
    $server->serve(500_000);
    $reply = json_decode((string) fgets($client), true);
    fclose($client);

    return $reply;
}

it('returns the credential to the allowed peer once', function () {
    $server = new CredentialSocketServer(app(CredentialReferences::class), fn () => 4242);
    $server->listen($this->path, 4242);
    $reference = app(CredentialReferences::class)->issue($this->account);

    expect(redeemOverSocket($server, $this->path, $reference))->toBe(['type' => 'oauth_token', 'value' => 'sk-ant-secret-value'])
        ->and(redeemOverSocket($server, $this->path, $reference))->toHaveKey('error');

    $server->close();
});

it('refuses a peer with another uid and leaves the reference unredeemed', function () {
    $server = new CredentialSocketServer(app(CredentialReferences::class), fn () => 1000);
    $server->listen($this->path, 4242);
    $reference = app(CredentialReferences::class)->issue($this->account);

    expect(redeemOverSocket($server, $this->path, $reference))->toBe(['error' => 'forbidden'])
        ->and(app(CredentialReferences::class)->redeem($reference))->not->toBeNull();

    $server->close();
});

it('refuses a peer whose uid the kernel did not report', function () {
    $server = new CredentialSocketServer(app(CredentialReferences::class), fn () => null);
    $server->listen($this->path, 4242);

    expect(redeemOverSocket($server, $this->path, 'x'))->toBe(['error' => 'forbidden']);

    $server->close();
});

it('makes the socket file readable and writable by its group only', function () {
    $server = new CredentialSocketServer(app(CredentialReferences::class), fn () => 4242);
    $server->listen($this->path, 4242);

    expect(substr(sprintf('%o', fileperms($this->path)), -4))->toBe('0660');

    $server->close();
});
