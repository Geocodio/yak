<?php

namespace App\Services\E2e;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A fake broker on the real Redis streams: answers start_runner, turn, steer and interrupt
 * with lines from the recorded runner streams (E2eRunnerScript).
 */
class E2eRunnerCommand extends Command
{
    private const int MAX_QUEUED_LINES = 5000;

    protected $signature = 'yak:e2e-runner
        {--pace=1 : speed of recorded time, 0 sends at once; the start script passes 4}
        {--once : one read and one flush, then exit}
        {--connection=threads : the Redis connection without a key prefix}';

    protected $description = 'Fake broker for the end-to-end journeys (e2e test environment only)';

    private bool $shouldStop = false;

    /** @var array<string, list<array{due: float, line: array<string, mixed>, fromBroker: bool}>> */
    private array $queues = [];

    public function handle(): int
    {
        if (! E2eMode::isActive()) {
            $this->error('yak:e2e-runner only runs in the e2e test environment.');

            return self::FAILURE;
        }

        $pace = (float) $this->option('pace');
        $connection = is_string($this->option('connection')) ? $this->option('connection') : 'threads';
        $wire = new E2eBrokerWire($connection);
        $script = new E2eRunnerScript(null, max(0.0, $pace));
        $isOnce = (bool) $this->option('once');

        if (function_exists('pcntl_signal')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->shouldStop = true;
            });
        }

        $this->info("yak:e2e-runner: serving broker channels on {$connection}");
        Log::info('yak:e2e-runner started', ['connection' => $connection, 'pace' => $pace]);

        $channels = [];
        $nextChannelRefresh = 0.0;
        $nextHousekeeping = 0.0;

        do {
            $now = microtime(true);

            if ($now >= $nextChannelRefresh) {
                $channels = $wire->channels();
                $nextChannelRefresh = $now + 1.0;
            }

            if ($now >= $nextHousekeeping) {
                $wire->heartbeat();
                foreach ($script->releaseExpired(now()) as $channel => $lines) {
                    $this->enqueue($channel, $lines, $now);
                }
                $nextHousekeeping = $now + 5.0;
            }

            if ($channels === []) {
                usleep(100_000);
            }

            foreach ($wire->readCommands($channels, 100, 20) as $entry) {
                $this->handleCommand($wire, $script, $entry);
            }

            $this->flushDue($wire);
        } while (! $isOnce && ! $this->shouldStop);

        return self::SUCCESS;
    }

    /**
     * @param  array{channel: string, id: string, command: array<string, mixed>|null, bytes: int}  $entry
     */
    private function handleCommand(E2eBrokerWire $wire, E2eRunnerScript $script, array $entry): void
    {
        $channel = $entry['channel'];
        $command = $entry['command'];

        if ($command === null) {
            Log::warning('yak:e2e-runner dropped a command it cannot read', ['channel' => $channel, 'bytes' => $entry['bytes']]);

            return;
        }

        $type = is_string($command['type'] ?? null) ? substr($command['type'], 0, 40) : '(none)';
        Log::info('yak:e2e-runner command', ['channel' => $channel, 'type' => $type, 'bytes' => $entry['bytes']]);

        $generation = $type === 'start_runner' ? $wire->startGeneration($channel) : $wire->generation($channel);
        $this->enqueue($channel, $script->respond($channel, $command, $generation), microtime(true));
    }

    /**
     * @param  list<array{afterMilliseconds: int, line: array<string, mixed>, fromBroker: bool}>  $lines
     */
    private function enqueue(string $channel, array $lines, float $now): void
    {
        $queue = $this->queues[$channel] ?? [];
        $dropped = 0;

        foreach ($lines as $scheduled) {
            if (count($queue) >= self::MAX_QUEUED_LINES) {
                $dropped++;

                continue;
            }
            $queue[] = ['due' => $now + $scheduled['afterMilliseconds'] / 1000, 'line' => $scheduled['line'], 'fromBroker' => $scheduled['fromBroker']];
        }

        usort($queue, fn (array $first, array $second): int => $first['due'] <=> $second['due']);
        $this->queues[$channel] = $queue;

        if ($dropped > 0) {
            Log::warning('yak:e2e-runner dropped scheduled lines', ['channel' => $channel, 'count' => $dropped]);
        }
    }

    private function flushDue(E2eBrokerWire $wire): void
    {
        $now = microtime(true);

        foreach ($this->queues as $channel => $queue) {
            while ($queue !== [] && $queue[0]['due'] <= $now) {
                $next = array_shift($queue);
                $wire->write($channel, $next['line'], $next['fromBroker']);
            }

            $this->queues[$channel] = $queue;
        }
    }
}
