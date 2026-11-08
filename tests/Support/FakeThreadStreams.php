<?php

namespace Tests\Support;

use App\DataTransferObjects\StreamEntry;
use App\Services\Threads\ThreadStreams;

class FakeThreadStreams extends ThreadStreams
{
    /** @var array<string, true> */
    public array $registered = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $commands = [];

    /** @var array<string, list<StreamEntry>> */
    private array $outbound = [];

    /** @var array<string, true> */
    private array $acked = [];

    /** @var array<string, int> */
    private array $failures = [];

    /** @var list<array{0: StreamEntry, 1: string}> */
    public array $deadLettered = [];

    /** @var list<string> every register and command in order, as "register:channel" or "command:type" */
    public array $events = [];

    public function register(string $channel): void
    {
        $this->registered[$channel] = true;
        $this->events[] = 'register:' . $channel;
    }

    public function unregister(string $channel): void
    {
        unset($this->registered[$channel]);
    }

    public function channels(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->registered), fn (string $channel): bool => str_starts_with($channel, $prefix)));
    }

    public function command(string $channel, array $command): string
    {
        $this->commands[$channel][] = $command;
        $this->events[] = 'command:' . $command['type'];

        return (string) count($this->commands[$channel]) . '-0';
    }

    public function read(array $channels, string $consumer, int $blockMilliseconds, int $count): array
    {
        $entries = [];

        foreach ($channels as $channel) {
            foreach ($this->outbound[$channel] ?? [] as $entry) {
                if (! isset($this->acked[$channel . '|' . $entry->id])) {
                    $entries[] = $entry;
                }
            }
        }

        return array_slice($entries, 0, $count);
    }

    public function ack(StreamEntry $entry): void
    {
        $this->acked[$entry->channel . '|' . $entry->id] = true;
    }

    public function recordFailure(StreamEntry $entry): int
    {
        return $this->failures[$entry->channel . '|' . $entry->id] = ($this->failures[$entry->channel . '|' . $entry->id] ?? 0) + 1;
    }

    public function deadLetter(StreamEntry $entry, string $reason): void
    {
        $this->deadLettered[] = [$entry, $reason];
        $this->acked[$entry->channel . '|' . $entry->id] = true;
    }

    public function readOnce(string $channel, string $afterId, int $blockMilliseconds): array
    {
        return array_values(array_filter(
            $this->outbound[$channel] ?? [],
            fn (StreamEntry $entry): bool => $this->compare($entry->id, $afterId) > 0,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commandsFor(string $channel): array
    {
        return $this->commands[$channel] ?? [];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function pushOutbound(string $channel, array $line, int $generation = 1, bool $fromBroker = false): void
    {
        $sequence = count($this->outbound[$channel] ?? []) + 1;
        $this->outbound[$channel][] = new StreamEntry($channel, $sequence . '-0', $generation, $sequence, $line, $fromBroker);
    }

    private function compare(string $left, string $right): int
    {
        return (int) $left <=> (int) $right;
    }
}
