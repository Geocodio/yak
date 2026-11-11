<?php

namespace App\Services\Threads;

use App\DataTransferObjects\StreamEntry;
use App\Services\Threads\Contracts\BrokerStreams;

/**
 * In-memory broker streams. Tests play the broker with pushOut() and read what
 * the code under test sent with commands(). Consumer-group semantics match
 * Redis: an unacknowledged entry is redelivered to its consumer first.
 */
class FakeBrokerStreams implements BrokerStreams
{
    /** @var array<string, true> */
    private array $registered = [];

    /** @var array<string, list<array{id: string, command: array<string, mixed>}>> */
    private array $inbound = [];

    /** @var array<string, list<StreamEntry>> */
    private array $outbound = [];

    /** @var array<string, array<string, string>> channel => entry id => consumer */
    private array $pending = [];

    /** @var array<string, array<string, true>> channel => entry id, delivered and acknowledged */
    private array $acknowledged = [];

    /** @var array<string, list<array{entry: StreamEntry, reason: string}>> */
    private array $dead = [];

    /** @var array<string, int> */
    private array $failures = [];

    private int $nextId = 1;

    public function register(string $channel): void
    {
        $this->registered[$channel] = true;
    }

    public function unregister(string $channel): void
    {
        unset($this->registered[$channel]);
    }

    public function channels(string $prefix): array
    {
        $channels = array_values(array_filter(array_keys($this->registered), fn (string $channel): bool => str_starts_with($channel, $prefix)));
        sort($channels);

        return $channels;
    }

    public function command(string $channel, array $command): string
    {
        $id = $this->newId();
        $this->inbound[$channel][] = ['id' => $id, 'command' => $command];

        return $id;
    }

    /**
     * Plays a line the broker copied from the runner (or wrote itself).
     *
     * @param  array<string, mixed>  $line
     */
    public function pushOut(string $channel, int $generation, int $sequence, array $line, bool $fromBroker = false): StreamEntry
    {
        $entry = new StreamEntry($channel, $this->newId(), $generation, $sequence, $line, $fromBroker);
        $this->outbound[$channel][] = $entry;

        return $entry;
    }

    /**
     * Commands sent to the channel, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function commands(string $channel): array
    {
        return array_column($this->inbound[$channel] ?? [], 'command');
    }

    /**
     * @return list<array{entry: StreamEntry, reason: string}>
     */
    public function deadLetters(string $channel): array
    {
        return $this->dead[$channel] ?? [];
    }

    /**
     * @return list<StreamEntry>
     */
    public function outbound(string $channel): array
    {
        return $this->outbound[$channel] ?? [];
    }

    public function read(array $channels, string $consumer, int $blockMilliseconds, int $count): array
    {
        $own = $new = [];

        foreach ($channels as $channel) {
            foreach ($this->outbound[$channel] ?? [] as $entry) {
                if (isset($this->acknowledged[$channel][$entry->id])) {
                    continue;
                }

                $owner = $this->pending[$channel][$entry->id] ?? null;

                if ($owner === $consumer) {
                    $own[] = $entry;
                } elseif ($owner === null) {
                    $new[] = $entry;
                }
            }
        }

        $entries = array_slice($own !== [] ? $own : $new, 0, $count);

        foreach ($entries as $entry) {
            $this->pending[$entry->channel][$entry->id] = $consumer;
        }

        return $entries;
    }

    public function ack(StreamEntry $entry): void
    {
        unset($this->pending[$entry->channel][$entry->id], $this->failures[$entry->channel . '|' . $entry->id]);
        $this->acknowledged[$entry->channel][$entry->id] = true;
    }

    public function trimBefore(string $channel, string $entryId): void
    {
        $this->outbound[$channel] = array_values(array_filter(
            $this->outbound[$channel] ?? [],
            fn (StreamEntry $entry): bool => $this->compareIds($entry->id, $entryId) >= 0,
        ));
    }

    public function delete(string $channel): void
    {
        unset($this->inbound[$channel], $this->outbound[$channel], $this->pending[$channel], $this->acknowledged[$channel], $this->dead[$channel]);
    }

    public function recordFailure(StreamEntry $entry): int
    {
        return $this->failures[$entry->channel . '|' . $entry->id] = ($this->failures[$entry->channel . '|' . $entry->id] ?? 0) + 1;
    }

    public function deadLetter(StreamEntry $entry, string $reason): void
    {
        $this->dead[$entry->channel][] = ['entry' => $entry, 'reason' => $reason];
        unset($this->pending[$entry->channel][$entry->id], $this->failures[$entry->channel . '|' . $entry->id]);
        $this->outbound[$entry->channel] = array_values(array_filter(
            $this->outbound[$entry->channel] ?? [],
            fn (StreamEntry $candidate): bool => $candidate->id !== $entry->id,
        ));
    }

    private function newId(): string
    {
        return sprintf('%d-0', $this->nextId++);
    }

    private function compareIds(string $left, string $right): int
    {
        return (int) $left <=> (int) $right;
    }
}
