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

    public function ack(StreamEntry $entry): void
    {
        $this->client()->xAck($entry->channel . ':out', self::GROUP, [$entry->id]);
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
                $entries[] = new StreamEntry(
                    $channel,
                    (string) $id,
                    (int) ($fields['generation'] ?? 0),
                    (int) ($fields['sequence'] ?? 0),
                    json_decode((string) ($fields['line'] ?? '{}'), true) ?: [],
                );
            }
        }

        return $entries;
    }
}
