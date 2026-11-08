<?php

namespace App\Console\Commands;

use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\CredentialSocketServer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:credential-socket')]
#[Description('Serve auth proxy placeholders to the broker over the credential Unix socket')]
class CredentialSocketCommand extends Command
{
    private bool $shouldStop = false;

    public function handle(AuthProxyPlaceholders $placeholders): int
    {
        $path = config('yak.credential_socket.path');
        $uid = config('yak.credential_socket.allowed_uid');

        if (! is_string($path) || $path === '' || ! is_int($uid)) {
            $this->error('YAK_CREDENTIAL_SOCKET and YAK_CREDENTIAL_SOCKET_UID must be set.');

            return self::FAILURE;
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });

        // Its own process: a slow turn end in the thread driver never delays a runner's credentials.
        $server = new CredentialSocketServer($placeholders);
        $server->listen($path, $uid);

        while (! $this->shouldStop) {
            $server->serve(500_000);
        }

        $server->close();

        return self::SUCCESS;
    }
}
