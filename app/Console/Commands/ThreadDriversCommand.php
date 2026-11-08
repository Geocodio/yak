<?php

namespace App\Console\Commands;

use App\DataTransferObjects\StreamEntry;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\CredentialSocketServer;
use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use Illuminate\Console\Command;
use Throwable;

class ThreadDriversCommand extends Command
{
    private const MAX_ATTEMPTS = 3;

    protected $signature = 'yak:thread-drivers {--once : Run a single read and tick, then exit}';

    protected $description = 'Consume the broker output streams and drive thread turns';

    private bool $shouldStop = false;

    public function handle(ThreadStreams $streams, ThreadDriver $driver, AuthProxyPlaceholders $placeholders): int
    {
        $this->trap([SIGTERM, SIGINT], function (int $signal): void {
            $this->shouldStop = true;
        });
        // A stable name: after a crash the restarted driver reads its own unacknowledged entries again.
        $consumer = gethostname() . '-thread-drivers';
        $credentialServer = $this->credentialServer($placeholders);

        do {
            $credentialServer?->serve(0);
            $channels = $streams->channels('threads:');

            // A channel's entries are handled in order: once one is left pending, the rest of that channel waits for the next read.
            $stalledChannels = [];

            foreach ($streams->read($channels, $consumer, 200, 100) as $entry) {
                if (isset($stalledChannels[$entry->channel])) {
                    continue;
                }

                if (! $this->process($entry, $streams, $driver)) {
                    $stalledChannels[$entry->channel] = true;
                }

                if ($this->shouldStop) {
                    return self::SUCCESS;
                }
            }

            try {
                $driver->tick();
            } catch (Throwable $exception) {
                report($exception);
            }

            if ($channels === [] && ! $this->option('once')) {
                $credentialServer === null ? usleep(500_000) : $credentialServer->serve(500_000);
            }
        } while (! $this->shouldStop && ! $this->option('once'));

        $credentialServer?->close();

        return self::SUCCESS;
    }

    /**
     * One entry never blocks the others: a failure is reported and the entry
     * stays pending to be tried again, and after MAX_ATTEMPTS it moves to the
     * dead-letter stream and only its thread's turn fails. Returns false while
     * the entry is still pending.
     */
    private function process(StreamEntry $entry, ThreadStreams $streams, ThreadDriver $driver): bool
    {
        try {
            $driver->handle($entry);
            $streams->ack($entry);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $attempts = $streams->recordFailure($entry);

            if ($attempts < self::MAX_ATTEMPTS) {
                usleep($attempts * 100_000);

                return false;
            }

            $streams->deadLetter($entry, $exception::class . ': ' . $exception->getMessage());

            try {
                $driver->failTurnOf($entry);
            } catch (Throwable $inner) {
                report($inner);
            }

            return true;
        }
    }

    /**
     * The broker redeems credential references here; without the broker's uid
     * configured no socket is served and no runner can start.
     */
    private function credentialServer(AuthProxyPlaceholders $placeholders): ?CredentialSocketServer
    {
        $path = config('yak.credential_socket.path');
        $uid = config('yak.credential_socket.allowed_uid');

        if (! is_string($path) || $path === '' || ! is_int($uid) || $this->option('once')) {
            return null;
        }

        $server = new CredentialSocketServer($placeholders);
        $server->listen($path, $uid);

        return $server;
    }
}
