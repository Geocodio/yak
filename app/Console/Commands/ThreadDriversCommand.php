<?php

namespace App\Console\Commands;

use App\DataTransferObjects\StreamEntry;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\ThreadDriver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Consumer loop over the broker streams: reads every registered thread
 * channel, hands each entry to the driver and acknowledges it. supervisord
 * starts this command.
 */
#[Signature('yak:thread-drivers {--once : Run a single read and tick, then exit}')]
#[Description('Consume runner output from the broker streams and drive threads')]
class ThreadDriversCommand extends Command
{
    private const MAX_ATTEMPTS = 3;

    private bool $shouldStop = false;

    public function handle(BrokerStreams $streams, ThreadDriver $driver): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });

        $consumer = gethostname() . '-' . getmypid();

        do {
            $channels = $streams->channels('threads:');

            $blockedChannels = [];

            foreach ($streams->read($channels, $consumer, 1000, 100) as $entry) {
                if (isset($blockedChannels[$entry->channel])) {
                    continue;
                }

                if (! $this->process($entry, $streams, $driver)) {
                    $blockedChannels[$entry->channel] = true;
                }
            }

            try {
                $driver->tick();
            } catch (Throwable $exception) {
                report($exception);
            }
        } while (! $this->shouldStop && ! $this->option('once'));

        return self::SUCCESS;
    }

    private function trim(StreamEntry $entry, ThreadDriver $driver): void
    {
        try {
            $driver->trimAfterAck($entry);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * A failing entry holds back only its own channel, in order: it is reported
     * and counted and stays pending (false), and the third failure fails the
     * thread's turn and dead-letters it so the channel moves on (true).
     */
    private function process(StreamEntry $entry, BrokerStreams $streams, ThreadDriver $driver): bool
    {
        try {
            $driver->handle($entry);
            $streams->ack($entry);
            $this->trim($entry, $driver);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            if ($streams->recordFailure($entry) < self::MAX_ATTEMPTS) {
                return false;
            }

            try {
                $driver->failTurnOf($entry);
            } catch (Throwable $inner) {
                report($inner);
            }

            $streams->deadLetter($entry, $exception->getMessage());
            $streams->ack($entry);

            return true;
        }
    }
}
