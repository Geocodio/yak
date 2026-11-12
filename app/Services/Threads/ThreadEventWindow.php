<?php

namespace App\Services\Threads;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * A short Redis window of every event of a thread, deltas included, so a reconnecting browser
 * can catch up without the database. Keys carry `yak.threads.realtime_key_prefix`.
 */
class ThreadEventWindow
{
    public function __construct(
        private readonly string $connection = 'threads',
        private readonly ?int $windowSeconds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function push(int $threadId, int $sequence, string $type, array $data, CarbonImmutable $at): void
    {
        $client = $this->client();
        $key = $this->key($threadId);
        $window = $this->window();

        $client->zAdd($key, $sequence, json_encode([
            'sequence' => $sequence,
            'type' => $type,
            'data' => $data,
            'createdAt' => $at->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        // Members are ordered by sequence, which is also creation order, so only the head can be stale.
        $cutoff = $at->subSeconds($window)->getTimestamp();

        while (($oldest = $client->zRange($key, 0, 0)) !== [] && $oldest !== false) {
            $decoded = json_decode((string) $oldest[0], true, 512, JSON_THROW_ON_ERROR);

            if (CarbonImmutable::parse($decoded['createdAt'])->getTimestamp() >= $cutoff) {
                break;
            }

            $client->zRem($key, $oldest[0]);
        }

        $client->expire($key, $window);
    }

    /**
     * Events with a sequence after `$after`, or null when the window no longer covers it.
     *
     * @return list<array<string, mixed>>|null
     */
    public function since(int $threadId, int $after, int $limit): ?array
    {
        $client = $this->client();
        $key = $this->key($threadId);

        $oldest = $client->zRange($key, 0, 0, true);

        if ($oldest === [] || $oldest === false || (int) array_values($oldest)[0] > $after + 1) {
            return null;
        }

        $members = $client->zRangeByScore($key, '(' . $after, '+inf', ['limit' => [0, $limit]]);

        return array_map(
            fn (string $member): array => json_decode($member, true, 512, JSON_THROW_ON_ERROR),
            array_values((array) $members),
        );
    }

    public function forget(int $threadId): void
    {
        $this->client()->del($this->key($threadId));
    }

    private function window(): int
    {
        return $this->windowSeconds ?? ((int) config('yak.threads.stream_catchup_minutes', 10)) * 60;
    }

    private function key(int $threadId): string
    {
        return config('yak.threads.realtime_key_prefix', '') . 'thread-events:' . $threadId;
    }

    private function client(): \Redis
    {
        /** @var \Redis */
        return Redis::connection($this->connection)->client();
    }
}
