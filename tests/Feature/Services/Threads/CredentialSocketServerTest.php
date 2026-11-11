<?php

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\CredentialSocketServer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = safeSocketDirectory();
    $this->path = $this->directory . '/redeem.sock';
    $this->account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-secret-value', 'kind' => ClaudeAccountKind::SetupToken]);
    $this->placeholders = app(AuthProxyPlaceholders::class);
});

afterEach(function () {
    @unlink($this->path);
    @chmod($this->directory, 0700);
    @rmdir($this->directory);
});

function safeSocketDirectory(int $mode = 02770): string
{
    $directory = sys_get_temp_dir() . '/yk' . getmypid() . '-' . Str::random(6);
    mkdir($directory, 0700);
    chgrp($directory, posix_getegid());
    chmod($directory, $mode);

    return $directory;
}

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

function allowedServer(string $path, ?int $reportedUid = -1): CredentialSocketServer
{
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class), fn () => $reportedUid === -1 ? posix_geteuid() : $reportedUid);
    $server->listen($path, posix_geteuid(), posix_geteuid(), posix_getegid());

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

it('serves a validation placeholder to the validation address and refuses other peers', function () {
    config(['yak.threads.auth_proxy_validation_address' => '172.30.0.2']);
    $server = allowedServer($this->path);
    $placeholder = $this->placeholders->issue('validation-' . $this->account->id, $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    expect(redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.30.0.2'))['secret'])->toBe('sk-ant-secret-value')
        ->and(redeemOverSocket($server, $this->path, redeemRequest($placeholder, '172.18.0.5')))->toBe(['error' => 'unknown']);

    $server->close();
});

it('binds a 0660 socket in the directory group without chown or chmod', function () {
    $server = allowedServer($this->path);

    expect(fileperms($this->path) & 0777)->toBe(0660)
        ->and(filegroup($this->path))->toBe(filegroup($this->directory));

    $server->close();
});

it('refuses a directory owned by another uid', function () {
    (new CredentialSocketServer($this->placeholders))->listen($this->path, 1, posix_geteuid() + 1, posix_getegid());
})->throws(RuntimeException::class, 'is not safe');

it('refuses a directory in another group', function () {
    (new CredentialSocketServer($this->placeholders))->listen($this->path, 1, posix_geteuid(), posix_getegid() + 1);
})->throws(RuntimeException::class, 'is not safe');

it('refuses a directory with a looser mode', function (int $mode) {
    $directory = safeSocketDirectory($mode);

    try {
        (new CredentialSocketServer($this->placeholders))->listen($directory . '/redeem.sock', 1, posix_geteuid(), posix_getegid());
    } finally {
        chmod($directory, 0700);
        rmdir($directory);
    }
})->with([0777, 0775, 0750, 03770])->throws(RuntimeException::class, 'is not safe');

it('refuses a symlinked directory', function () {
    $link = $this->directory . '-link';
    symlink($this->directory, $link);

    try {
        (new CredentialSocketServer($this->placeholders))->listen($link . '/redeem.sock', 1, posix_geteuid(), posix_getegid());
    } finally {
        unlink($link);
    }
})->throws(RuntimeException::class, 'not a real directory');

it('refuses to unlink a non-socket at the path', function () {
    file_put_contents($this->path, 'precious');

    try {
        (new CredentialSocketServer($this->placeholders))->listen($this->path, 1, posix_geteuid(), posix_getegid());
    } finally {
        expect(file_get_contents($this->path))->toBe('precious');
    }
})->throws(RuntimeException::class, 'Refusing to remove');

it('replaces a stale socket it owns', function () {
    allowedServer($this->path)->close();
    $server = allowedServer($this->path);

    expect(filetype($this->path))->toBe('socket');

    $server->close();
});

it('lets the allowed uid connect and refuses another uid', function () {
    $server = new CredentialSocketServer(app(AuthProxyPlaceholders::class));
    $server->listen($this->path, 991);
    // The forked children keep root's supplementary group, so move the socket to a group nobody holds.
    chgrp($this->path, 7777);

    $connectAs = function (int $uid): int {
        $pid = pcntl_fork();

        if ($pid === 0) {
            posix_setuid($uid);
            $client = socket_create(AF_UNIX, SOCK_STREAM, 0);
            $connected = @socket_connect($client, $this->path);
            exit($connected ? 0 : socket_last_error($client));
        }

        pcntl_waitpid($pid, $status);

        return pcntl_wexitstatus($status);
    };

    expect($connectAs(991))->toBe(0)
        ->and($connectAs(12345))->toBe(13);

    $server->close();
})->skip(fn () => posix_geteuid() !== 0, 'needs root to chown and setuid');
