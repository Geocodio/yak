<?php

use App\Services\Threads\ThreadStreams;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    try {
        $this->redis = Redis::connection('threads')->client();
        $this->redis->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not reachable.');
    }

    $this->channel = 'threads:test-' . uniqid();
    $this->streams = new ThreadStreams;
});

afterEach(function () {
    if (isset($this->redis, $this->channel)) {
        $this->redis->del($this->channel . ':in', $this->channel . ':out', $this->channel . ':dead');
        $this->streams->unregister($this->channel);
    }
});

it('registers and lists channels by prefix', function () {
    $this->streams->register($this->channel);

    expect($this->streams->channels('threads:test-'))->toContain($this->channel);
    expect($this->streams->channels('oneshot:'))->not->toContain($this->channel);

    $this->streams->unregister($this->channel);

    expect($this->streams->channels('threads:test-'))->not->toContain($this->channel);
});

it('writes commands to the in stream under the command field', function () {
    $id = $this->streams->command($this->channel, ['type' => 'end']);

    $entries = $this->redis->xRange($this->channel . ':in', '-', '+');

    expect($entries)->toHaveCount(1)
        ->and(array_key_first($entries))->toBe($id)
        ->and(json_decode($entries[$id]['command'], true))->toBe(['type' => 'end']);
});

it('reads out entries as a consumer group and redelivers unacknowledged ones', function () {
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '2', 'sequence' => '7', 'line' => '{"type":"system"}']);

    $first = $this->streams->read([$this->channel], 'consumer-a', 50, 10);

    expect($first)->toHaveCount(1)
        ->and($first[0]->generation)->toBe(2)
        ->and($first[0]->sequence)->toBe(7)
        ->and($first[0]->line)->toBe(['type' => 'system'])
        ->and($first[0]->channel)->toBe($this->channel);

    $again = $this->streams->read([$this->channel], 'consumer-a', 50, 10);
    expect($again)->toHaveCount(1)->and($again[0]->id)->toBe($first[0]->id);

    $this->streams->ack($first[0]);

    expect($this->streams->read([$this->channel], 'consumer-a', 50, 10))->toBe([]);
});

it('reads one-shot entries after an id without a group', function () {
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => '1', 'line' => '{"a":1}']);

    $entries = $this->streams->readOnce($this->channel, '0', 50);

    expect($entries)->toHaveCount(1)
        ->and($this->streams->readOnce($this->channel, $entries[0]->id, 50))->toBe([]);
});

it('marks entries from the broker by the origin field only', function () {
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => '1', 'line' => '{"type":"broker"}']);
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => '2', 'line' => '{"type":"broker"}', 'origin' => 'broker']);

    $entries = $this->streams->read([$this->channel], 'consumer-a', 50, 10);

    expect($entries[0]->fromBroker)->toBeFalse()
        ->and($entries[1]->fromBroker)->toBeTrue();
});

it('moves a malformed entry to the dead letters instead of returning it', function () {
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => 'x', 'sequence' => '1', 'line' => 'not json']);
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => '2', 'line' => '{"type":"system"}']);

    $entries = $this->streams->read([$this->channel], 'consumer-a', 50, 10);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->sequence)->toBe(2)
        ->and($this->redis->xLen($this->channel . ':dead'))->toBe(1)
        ->and($this->redis->xLen($this->channel . ':out'))->toBe(1);
});

it('dead-letters an entry after counting failures and removes it from the out stream', function () {
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => '1', 'line' => '{"type":"system"}']);
    $entry = $this->streams->read([$this->channel], 'consumer-a', 50, 10)[0];

    expect($this->streams->recordFailure($entry))->toBe(1)
        ->and($this->streams->recordFailure($entry))->toBe(2);

    $this->streams->deadLetter($entry, 'boom');

    $dead = $this->redis->xRange($this->channel . ':dead', '-', '+');
    expect(array_values($dead)[0]['reason'])->toBe('boom')
        ->and($this->redis->xLen($this->channel . ':out'))->toBe(0)
        ->and($this->streams->read([$this->channel], 'consumer-a', 50, 10))->toBe([])
        ->and($this->streams->recordFailure($entry))->toBe(1);
    $this->redis->hDel('broker:attempts', $this->channel . '|' . $entry->id);
});

it('dead-letters an entry whose line cannot be re-encoded', function () {
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => '1', 'line' => '{"n":1e999}']);
    $entry = $this->streams->read([$this->channel], 'consumer-a', 50, 10)[0];

    $this->streams->deadLetter($entry, 'boom');

    expect($this->redis->xLen($this->channel . ':dead'))->toBe(1)
        ->and($this->redis->xLen($this->channel . ':out'))->toBe(0);
});

it('claims entries a crashed consumer left behind', function () {
    $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => '1', 'line' => '{"type":"system"}']);
    $this->streams->read([$this->channel], 'dead-consumer', 50, 10);
    $this->streams->claimIdleMilliseconds = 300;
    usleep(500_000);

    expect($this->streams->read([$this->channel], 'new-consumer', 50, 10))->toHaveCount(1);
});

it('drops entries before the acknowledged one but keeps the acknowledged entry', function () {
    foreach ([1, 2, 3] as $sequence) {
        $this->redis->xAdd($this->channel . ':out', '*', ['generation' => '1', 'sequence' => (string) $sequence, 'line' => '{"a":1}']);
    }

    $entries = $this->streams->read([$this->channel], 'consumer-a', 50, 10);
    $this->streams->ack($entries[1]);

    $remaining = array_keys($this->redis->xRange($this->channel . ':out', '-', '+'));
    expect($remaining)->toBe([$entries[1]->id, $entries[2]->id]);
});

it('deletes all three streams of a channel', function () {
    $this->streams->command($this->channel, ['type' => 'end']);
    $this->redis->xAdd($this->channel . ':out', '*', ['line' => '{}']);
    $this->redis->xAdd($this->channel . ':dead', '*', ['line' => '{}']);

    $this->streams->delete($this->channel);

    expect($this->redis->exists($this->channel . ':in', $this->channel . ':out', $this->channel . ':dead'))->toBe(0);
});
