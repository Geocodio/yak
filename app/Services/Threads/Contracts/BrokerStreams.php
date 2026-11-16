<?php

namespace App\Services\Threads\Contracts;

use App\DataTransferObjects\StreamEntry;

/**
 * PHP side of the pipe broker's Redis streams. `{channel}:in` carries
 * commands to the broker, `{channel}:out` carries runner and broker lines
 * back. Channels are `threads:{threadId}` and `runs:{runId}`. The wire format
 * is written down in `broker/PROTOCOL.md`.
 */
interface BrokerStreams
{
    public function register(string $channel): void;

    public function unregister(string $channel): void;

    /**
     * Registered channels that start with the prefix, sorted.
     *
     * @return list<string>
     */
    public function channels(string $prefix): array;

    /**
     * Appends a command to the channel's inbound stream and returns its entry id.
     *
     * @param  array<string, mixed>  $command
     */
    public function command(string $channel, array $command): string;

    /**
     * Entries already delivered to this consumer but never acknowledged come
     * first; new entries are read only when none are pending.
     *
     * @param  list<string>  $channels
     * @return list<StreamEntry>
     */
    public function read(array $channels, string $consumer, int $blockMilliseconds, int $count): array;

    public function ack(StreamEntry $entry): void;

    /** Drops every outbound entry of the channel older than the entry id. */
    public function trimBefore(string $channel, string $entryId): void;

    /** Removes a finished channel's streams, so no transcript stays in Redis. */
    public function delete(string $channel): void;

    /** Counts one failed handling of an entry and returns how many have failed. */
    public function recordFailure(StreamEntry $entry): int;

    /** Moves an entry that keeps failing to the channel's dead-letter stream. */
    public function deadLetter(StreamEntry $entry, string $reason): void;
}
