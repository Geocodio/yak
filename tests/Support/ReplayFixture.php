<?php

use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use Illuminate\Support\Facades\Artisan;

/**
 * Pushes a recorded runner stream into the thread's channel as generation 1
 * entries and runs the consumer command until every entry is acknowledged.
 * Header lines and lines the driver itself wrote (keys starting with `_driver`)
 * are skipped. Broker lines are marked as coming from the broker.
 *
 * @param  int|null  $limit  stop after this many lines, to look at a turn mid-stream
 */
function replayFixtureThroughConsumer(Thread $thread, string $name, ?int $limit = null): void
{
    $streams = app(BrokerStreams::class);
    $channel = 'threads:' . $thread->id;
    $streams->register($channel);
    $sequence = 0;

    foreach (file(base_path("tests/Fixtures/runner-streams/{$name}.jsonl"), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $line = json_decode($raw, true);

        if (! is_array($line) || isset($line['fixture']) || array_filter(array_keys($line), fn (string|int $key): bool => str_starts_with((string) $key, '_driver')) !== []) {
            continue;
        }

        if ($limit !== null && $sequence >= $limit) {
            break;
        }

        $streams->pushOut($channel, (int) ($line['generation'] ?? 1), ++$sequence, $line, fromBroker: str_starts_with((string) ($line['type'] ?? ''), 'broker'));
    }

    for ($pass = 0; $pass <= intdiv($sequence, 100) + 1; $pass++) {
        Artisan::call('yak:thread-drivers', ['--once' => true]);
    }
}
