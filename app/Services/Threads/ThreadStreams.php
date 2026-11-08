<?php

namespace App\Services\Threads;

use App\DataTransferObjects\StreamEntry;
use Illuminate\Support\Facades\Redis;

/**
 * PHP side of the broker's Redis streams: `{channel}:in` carries commands to
 * the broker, `{channel}:out` carries runner lines back.
 */
class ThreadStreams
{
    private const CHANNEL_SET = 'broker:channels';

    private const GROUP = 'drivers';

    private const ATTEMPTS_KEY = 'broker:attempts';

    /** Entries another consumer left pending this long are claimed by this one. */
    public int $claimIdleMilliseconds = 60_000;

    public function register(string $channel): void
    {
        $this->client()->sAdd(self::CHANNEL_SET, $channel);
    }

    public function unregister(string $channel): void
    {
        $this->client()->sRem(self::CHANNEL_SET, $channel);
    }

    /**
     * @return list<string>
     */
    public function channels(string $prefix): array
    {
        $channels = array_filter(
            (array) $this->client()->sMembers(self::CHANNEL_SET),
            fn (string $channel): bool => str_starts_with($channel, $prefix),
        );
        sort($channels);

        return $channels;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public function command(string $channel, array $command): string
    {
        return (string) $this->client()->xAdd($channel . ':in', '*', ['command' => json_encode($command, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Entries already delivered to this consumer but never acknowledged come
     * first; new entries are read only when none are pending.
     *
     * @param  list<string>  $channels
     * @return list<StreamEntry>
     */
    public function read(array $channels, string $consumer, int $blockMilliseconds, int $count): array
    {
        if ($channels === []) {
            return [];
        }

        $client = $this->client();

        foreach ($channels as $channel) {
            $client->xGroup('CREATE', $channel . ':out', self::GROUP, '0', true);
        }

        $this->claimAbandoned($channels, $consumer, $count);

        foreach (['0', '>'] as $position) {
            $streams = array_fill_keys(array_map(fn (string $channel): string => $channel . ':out', $channels), $position);
            $reply = $position === '>'
                ? $client->xReadGroup(self::GROUP, $consumer, $streams, $count, $blockMilliseconds)
                : $client->xReadGroup(self::GROUP, $consumer, $streams, $count);
            $entries = $this->entries($reply);

            if ($entries !== []) {
                return $entries;
            }
        }

        return [];
    }

    /**
     * Acknowledges a handled entry and drops everything before it from the out
     * stream. Entries of a channel are handled in order, so nothing older is
     * still pending. The acked entry itself stays: the broker reads the last
     * entry to continue its generation and sequence after a restart.
     */
    public function ack(StreamEntry $entry): void
    {
        $this->client()->xAck($entry->channel . ':out', self::GROUP, [$entry->id]);
        $this->client()->xTrim($entry->channel . ':out', $entry->id, false, true);
        $this->client()->hDel(self::ATTEMPTS_KEY, $this->attemptKey($entry));
    }

    /**
     * Removes a finished channel's streams, so no transcript stays in Redis.
     */
    public function delete(string $channel): void
    {
        $this->client()->del($channel . ':in', $channel . ':out', $channel . ':dead');
    }

    /**
     * Counts one failed handling of an entry and returns how many have failed.
     * The count lives in Redis so it survives a driver restart.
     */
    public function recordFailure(StreamEntry $entry): int
    {
        return (int) $this->client()->hIncrBy(self::ATTEMPTS_KEY, $this->attemptKey($entry), 1);
    }

    /**
     * Moves an entry that keeps failing to the channel's dead-letter stream and
     * takes it out of the out stream, so it blocks nothing.
     */
    public function deadLetter(StreamEntry $entry, string $reason): void
    {
        $this->moveToDeadLetters($entry->channel, $entry->id, [
            'generation' => (string) $entry->generation,
            'sequence' => (string) $entry->sequence,
            'line' => json_encode($entry->line, JSON_THROW_ON_ERROR),
            'origin' => $entry->fromBroker ? 'broker' : '',
        ], $reason);
        $this->client()->hDel(self::ATTEMPTS_KEY, $this->attemptKey($entry));
    }

    /**
     * Plain XREAD for one-shot channels that have no consumer group.
     *
     * @return list<StreamEntry>
     */
    public function readOnce(string $channel, string $afterId, int $blockMilliseconds): array
    {
        return $this->entries($this->client()->xRead([$channel . ':out' => $afterId], 100, $blockMilliseconds));
    }

    /**
     * @param  list<string>  $channels
     */
    private function claimAbandoned(array $channels, string $consumer, int $count): void
    {
        foreach ($channels as $channel) {
            try {
                $this->client()->xAutoClaim($channel . ':out', self::GROUP, $consumer, $this->claimIdleMilliseconds, '0-0', $count);
            } catch (\RedisException) {
                // XAUTOCLAIM needs Redis 6.2; without it, entries stay with their consumer.
            }
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function moveToDeadLetters(string $channel, string $id, array $fields, string $reason): void
    {
        $client = $this->client();
        $client->xAdd($channel . ':dead', '*', ['entry_id' => $id, 'reason' => substr($reason, 0, 500), 'failed_at' => (string) time()] + $fields, 1000, true);
        $client->xAck($channel . ':out', self::GROUP, [$id]);
        $client->xDel($channel . ':out', [$id]);
    }

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<string, string>
     */
    private function scalarFields(array $fields): array
    {
        $scalars = [];

        foreach ($fields as $name => $value) {
            if (is_scalar($value)) {
                $scalars[(string) $name] = (string) $value;
            }
        }

        return $scalars;
    }

    private function attemptKey(StreamEntry $entry): string
    {
        return $entry->channel . '|' . $entry->id;
    }

    /**
     * @return \Redis
     */
    private function client()
    {
        return Redis::connection('threads')->client();
    }

    /**
     * @return list<StreamEntry>
     */
    private function entries(mixed $reply): array
    {
        $entries = [];

        foreach (is_array($reply) ? $reply : [] as $stream => $messages) {
            $channel = substr((string) $stream, 0, -strlen(':out'));

            foreach ($messages as $id => $fields) {
                $line = is_string($fields['line'] ?? null) ? json_decode($fields['line'], true) : null;

                // An entry that is not a JSON object with numeric generation and sequence can never be handled.
                if (! is_array($line) || ! ctype_digit((string) ($fields['generation'] ?? '')) || ! ctype_digit((string) ($fields['sequence'] ?? ''))) {
                    $this->moveToDeadLetters($channel, (string) $id, $this->scalarFields((array) $fields), 'malformed entry');

                    continue;
                }

                $entries[] = new StreamEntry(
                    $channel,
                    (string) $id,
                    (int) $fields['generation'],
                    (int) $fields['sequence'],
                    $line,
                    ($fields['origin'] ?? null) === 'broker',
                );
            }
        }

        return $entries;
    }
}
