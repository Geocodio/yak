<?php

namespace App\Console\Commands;

use App\DataTransferObjects\StreamEntry;
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

    public function handle(ThreadStreams $streams, ThreadDriver $driver): int
    {
        $this->trap([SIGTERM, SIGINT], function (int $signal): void {
            $this->shouldStop = true;
        });
        // A stable name: after a crash the restarted driver reads its own unacknowledged entries again.
        $consumer = gethostname() . '-thread-drivers';

        do {
            $channels = $streams->channels('threads:');

            // A channel's entries are handled in order: once one is left pending, the rest of that channel waits for the next read.
            $stalledChannels = [];

            foreach ($streams->read($channels, $consumer, 50, 100) as $entry) {
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

            $driver->flushDueDeltas();

            try {
                $driver->tick();
            } catch (Throwable $exception) {
                report($exception);
            }

            if ($channels === [] && ! $this->option('once')) {
                usleep(500_000);
            }
        } while (! $this->shouldStop && ! $this->option('once'));

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

            try {
                $streams->deadLetter($entry, $exception::class . ': ' . $exception->getMessage());
            } catch (Throwable $deadLetterFailure) {
                // The entry must still leave the out stream, or it would be claimed and fail forever.
                report($deadLetterFailure);
                $streams->ack($entry);
            }

            try {
                $driver->failTurnOf($entry);
            } catch (Throwable $inner) {
                report($inner);
            }

            return true;
        }
    }
}
