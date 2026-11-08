<?php

namespace App\Console\Commands;

use App\Services\Threads\CredentialReferences;
use App\Services\Threads\CredentialSocketServer;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use Illuminate\Console\Command;

class ThreadDriversCommand extends Command
{
    protected $signature = 'yak:thread-drivers {--once : Run a single read and tick, then exit}';

    protected $description = 'Consume the broker output streams and drive thread turns';

    private bool $shouldStop = false;

    public function handle(ThreadStreams $streams, ThreadDriver $driver, CredentialReferences $references): int
    {
        $this->trap([SIGTERM, SIGINT], function (int $signal): void {
            $this->shouldStop = true;
        });
        $consumer = gethostname() . '-' . getmypid();
        $credentialServer = $this->credentialServer($references);

        do {
            $credentialServer?->serve(0);
            $channels = $streams->channels('threads:');

            foreach ($streams->read($channels, $consumer, 200, 100) as $entry) {
                $driver->handle($entry);
                $streams->ack($entry);

                if ($this->shouldStop) {
                    return self::SUCCESS;
                }
            }

            $driver->tick();

            if ($channels === [] && ! $this->option('once')) {
                $credentialServer === null ? usleep(500_000) : $credentialServer->serve(500_000);
            }
        } while (! $this->shouldStop && ! $this->option('once'));

        $credentialServer?->close();

        return self::SUCCESS;
    }

    /**
     * The broker redeems credential references here; without the broker's uid
     * configured no socket is served and no runner can start.
     */
    private function credentialServer(CredentialReferences $references): ?CredentialSocketServer
    {
        $path = config('yak.credential_socket.path');
        $uid = config('yak.credential_socket.allowed_uid');

        if (! is_string($path) || $path === '' || ! is_int($uid) || $this->option('once')) {
            return null;
        }

        $server = new CredentialSocketServer($references);
        $server->listen($path, $uid);

        return $server;
    }
}
