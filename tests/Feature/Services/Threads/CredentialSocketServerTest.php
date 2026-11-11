<?php

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\CredentialSocketServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->path = sys_get_temp_dir() . '/yk' . getmypid() . '-' . Str::random(6) . '.sock';
    $this->account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-secret-value', 'kind' => ClaudeAccountKind::SetupToken]);
    $this->placeholders = app(AuthProxyPlaceholders::class);
});

afterEach(function () {
    @unlink($this->path);
});

/**
 * @return array<string, mixed>
 */
function redeemOverSocket(CredentialSocketServer $server, string $path, string $request): array
{
    $client = stream_socket_client('unix://' . $path, $errorCode, $errorMessage, 2);
    fwrite($client, $request . "\n");
    $server->serve(500_000);
    $reply = json_decode((string) fgets($client), true);
    fclose($client);

    return $reply;
}

function redeemRequest(string $placeholder, string $containerIp): string
{
    return json_encode(['placeholder' => $placeholder, 'container_ip' => $containerIp]);
}

function allowedServer(string $path, ?int $reportedUid = 4242): CredentialSocketServer
{
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => $reportedUid);
    $server->listen($path, 4242);

    return $server;
}

it('returns kind, secret and account id to the allowed peer', function () {
    $server = allowedServer($this->path);
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), $this->account, '172.20.0.5');

    expect(redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.20.0.5')))
        ->toBe(['kind' => 'setup_token', 'secret' => 'sk-ant-secret-value', 'account_id' => $this->account->id]);

    $server->close();
});

it('an api key account replies kind api_key', function () {
    $account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-api03-abcdefgh', 'kind' => ClaudeAccountKind::ApiKey]);
    $server = allowedServer($this->path);
    $placeholder = $this->placeholders->issue('run:7', $account, '172.20.0.6');

    expect(redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.20.0.6')))->toMatchArray(['kind' => 'api_key', 'account_id' => $account->id]);

    $server->close();
});

it('a revoked placeholder gets unknown', function () {
    $server = allowedServer($this->path);
    $thread = Thread::factory()->create();
    $placeholder = $this->placeholders->issue($thread, $this->account, '172.20.0.5');
    $this->placeholders->revoke($thread);

    expect(redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.20.0.5')))->toBe(['error' => 'unknown']);

    $server->close();
});

it('a wrong container ip gets unknown', function () {
    $server = allowedServer($this->path);
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), $this->account, '172.20.0.5');

    expect(redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.20.0.9')))->toBe(['error' => 'unknown']);

    $server->close();
});

it('refuses a peer with another uid and reveals nothing', function () {
    $server = allowedServer($this->path, 1000);
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), $this->account, '172.20.0.5');

    expect(redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.20.0.5')))->toBe(['error' => 'forbidden']);

    $server->close();
});

it('refuses a peer whose uid the kernel did not report', function () {
    $server = allowedServer($this->path, null);

    expect(redeemOverSocket($server, $this->path, redeemRequest('x', '1.1.1.1')))->toBe(['error' => 'forbidden']);

    $server->close();
});

it('makes the socket file mode 0660', function () {
    $server = allowedServer($this->path);

    expect(substr(sprintf('%o', fileperms($this->path)), -4))->toBe('0660');

    $server->close();
});

it('replaces a stale socket file on listen', function () {
    file_put_contents($this->path, 'stale');
    $server = allowedServer($this->path);

    expect(filetype($this->path))->toBe('socket');

    $server->close();
});

it('malformed json gets unknown without throwing', function () {
    $server = allowedServer($this->path);

    expect(redeemOverSocket($server, $this->path, '{not json'))->toBe(['error' => 'unknown']);

    $server->close();
});

it('an oversized request gets unknown without throwing', function () {
    $server = allowedServer($this->path);

    expect(redeemOverSocket($server, $this->path, str_repeat('a', 6_000)))->toBe(['error' => 'unknown']);

    $server->close();
});

it('never logs the secret and a forbidden reply carries none', function () {
    Log::spy();
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), $this->account, '172.20.0.5');

    $server = allowedServer($this->path);
    redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.20.0.5'));
    $server->close();

    $denied = allowedServer($this->path, 1000);
    $forbidden = redeemOverSocket($denied, $this->path, redeemRequest($placeholder, '172.20.0.5'));
    $denied->close();

    expect(json_encode($forbidden))->not->toContain('sk-ant-secret-value');
    Log::shouldNotHaveReceived('channel');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});
