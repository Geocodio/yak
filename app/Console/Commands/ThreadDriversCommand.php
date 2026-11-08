<?php

namespace App\Console\Commands;

use App\Services\Threads\ThreadDriver;
use App\Services\Threads\ThreadStreams;
use Illuminate\Console\Command;

class ThreadDriversCommand extends Command
{
    protected $signature = 'yak:thread-drivers {--once : Run a single read and tick, then exit}';

    protected $description = 'Consume the broker output streams and drive thread turns';

    private bool $shouldStop = false;

    public function handle(ThreadStreams $streams, ThreadDriver $driver): int
    {
        $this->trap([SIGTERM, SIGINT], function (int $signal): void {
            $this->shouldStop = true;
        });
        $consumer = gethostname() . '-' . getmypid();

        do {
            $channels = $streams->channels('threads:');

            foreach ($streams->read($channels, $consumer, 2000, 100) as $entry) {
                $driver->handle($entry);
                $streams->ack($entry);

                if ($this->shouldStop) {
                    return self::SUCCESS;
                }
            }

            $driver->tick();

            if ($channels === [] && ! $this->option('once')) {
                usleep(500_000);
            }
        } while (! $this->shouldStop && ! $this->option('once'));

        return self::SUCCESS;
    }
}
