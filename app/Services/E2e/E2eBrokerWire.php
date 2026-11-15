<?php

namespace App\Services\E2e;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * The broker's side of the Redis streams, for the fake runner only (broker/PROTOCOL.md section 1).
 * The connection has no key prefix: the broker uses bare names.
 */
final class E2eBrokerWire
{
    public const int MAX_CHANNELS = 200;

    public const int MAX_COMMAND_BYTES = 65_536;

    private const string CHANNEL_SET = 'broker:channels';

    private const string GROUP = 'broker';

    private const string CONSUMER = 'e2e-runner';

    /** @var array<string, array{generation: int, sequence: int}> */
    private array $positions = [];

    private bool $hasLoggedChannelCap = false;

    /** @var array<string, true> channels whose consumer group exists */
    private array $groupedChannels = [];

    public function __construct(private readonly string $connection = 'threads') {}

    /**
     * Members of `broker:channels`, sorted, at most MAX_CHANNELS (more are logged once by count).
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $channels = array_values(array_filter((array) $this->client()->sMembers(self::CHANNEL_SET), 'is_string'));
        sort($channels);

        if (count($channels) > self::MAX_CHANNELS) {
            if (! $this->hasLoggedChannelCap) {
                Log::warning('yak:e2e-runner serves only the first channels', ['served' => self::MAX_CHANNELS, 'registered' => count($channels)]);
                $this->hasLoggedChannelCap = true;
            }
            $channels = array_slice($channels, 0, self::MAX_CHANNELS);
        }

        return $channels;
    }

    /**
     * Reads `{channel}:in` with consumer group `broker` (created with MKSTREAM when missing), deletes every
     * entry it read, parsed or not, as the broker does. `command` is null for an entry that is not a JSON object
     * or is larger than MAX_COMMAND_BYTES.
     *
     * @param  list<string>  $channels
     * @return list<array{channel: string, id: string, command: array<string, mixed>|null, bytes: int}>
     */
    public function readCommands(array $channels, int $blockMilliseconds, int $count): array
    {
        if ($channels === []) {
            return [];
        }

        $client = $this->client();
        $streams = [];

        foreach ($channels as $channel) {
            if (! isset($this->groupedChannels[$channel])) {
                $client->xGroup('CREATE', $channel . ':in', self::GROUP, '0', true);
                $this->groupedChannels[$channel] = true;
            }
            $streams[$channel . ':in'] = '>';
        }

        $reply = $client->xReadGroup(self::GROUP, self::CONSUMER, $streams, $count, $blockMilliseconds);
        $commands = [];

        foreach (is_array($reply) ? $reply : [] as $stream => $messages) {
            $channel = substr((string) $stream, 0, -strlen(':in'));

            foreach ($messages as $id => $fields) {
                $raw = is_array($fields) && is_string($fields['command'] ?? null) ? $fields['command'] : '';
                $bytes = strlen($raw);
                $decoded = $bytes <= self::MAX_COMMAND_BYTES ? json_decode($raw, true) : null;
                $isObject = is_array($decoded) && ! array_is_list($decoded);

                $commands[] = ['channel' => $channel, 'id' => (string) $id, 'command' => $isObject ? $decoded : null, 'bytes' => $bytes];
                $client->xAck($channel . ':in', self::GROUP, [(string) $id]);
                $client->xDel($channel . ':in', [(string) $id]);
            }
        }

        return $commands;
    }

    /** The channel's current generation, from memory or, after a restart, from its last `:out` entry; 0 when none. */
    public function generation(string $channel): int
    {
        return $this->position($channel)['generation'];
    }

    /** Increments the channel's generation, as a `start_runner` does, and returns it. */
    public function startGeneration(string $channel): int
    {
        $position = $this->position($channel);
        $this->positions[$channel] = ['generation' => $position['generation'] + 1, 'sequence' => $position['sequence']];

        return $this->positions[$channel]['generation'];
    }

    /**
     * XADD to `{channel}:out` with `generation`, the next `sequence`, `line` as JSON and `origin` (`broker` or empty).
     *
     * @param  array<string, mixed>  $line
     * @param  int|null  $generation  the generation the line was answered in; the channel's current one when null
     * @return string the entry id
     */
    public function write(string $channel, array $line, bool $fromBroker, ?int $generation = null): string
    {
        $position = $this->position($channel);
        $sequence = $position['sequence'] + 1;
        $this->positions[$channel] = ['generation' => $position['generation'], 'sequence' => $sequence];

        return (string) $this->client()->xAdd($channel . ':out', '*', [
            'generation' => (string) ($generation ?? $position['generation']),
            'sequence' => (string) $sequence,
            'line' => json_encode($line, JSON_THROW_ON_ERROR),
            'origin' => $fromBroker ? 'broker' : '',
        ]);
    }

    /** Sets `broker:alive` the way the broker does: the unix time, expiring after 15 seconds. */
    public function heartbeat(): void
    {
        $this->client()->set('broker:alive', (string) time(), ['ex' => 15]);
    }

    /**
     * @return array{generation: int, sequence: int}
     */
    private function position(string $channel): array
    {
        if (isset($this->positions[$channel])) {
            return $this->positions[$channel];
        }

        $last = $this->client()->xRevRange($channel . ':out', '+', '-', 1);
        $fields = is_array($last) ? (array) reset($last) : [];

        return $this->positions[$channel] = [
            'generation' => (int) ($fields['generation'] ?? 0),
            'sequence' => (int) ($fields['sequence'] ?? 0),
        ];
    }

    /**
     * @return \Redis
     */
    private function client()
    {
        return Redis::connection($this->connection)->client();
    }
}
