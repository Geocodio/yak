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
        $this->redis->del($this->channel . ':in', $this->channel . ':out');
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
