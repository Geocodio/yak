<?php

use App\Services\Threads\DeltaBatcher;

it('batches deltas of one block for 50 ms', function () {
    $batcher = new DeltaBatcher;
    $batcher->add(1, 'm1', 0, 'text', 'Hel', 0.000);
    $batcher->add(1, 'm1', 0, 'text', 'lo ', 0.020);
    $batcher->add(1, 'm1', 0, 'text', 'there', 0.040);

    expect($batcher->due(0.045))->toBe([])
        ->and($batcher->due(0.051))->toBe([['threadId' => 1, 'blockKey' => 'm1:0', 'kind' => 'text', 'text' => 'Hello there', 'offset' => 0]])
        ->and($batcher->due(0.200))->toBe([]);
});

it('hands over everything buffered for one thread early', function () {
    $batcher = new DeltaBatcher;
    $batcher->add(1, 'm1', 0, 'text', 'a', 0.0);
    $batcher->add(2, 'm2', 0, 'thinking', 'b', 0.0);

    expect($batcher->takeThread(1))->toBe([['threadId' => 1, 'blockKey' => 'm1:0', 'kind' => 'text', 'text' => 'a', 'offset' => 0]])
        ->and($batcher->takeThread(1))->toBe([])
        ->and($batcher->due(1.0))->toHaveCount(1);
});

it('splits a 20 KB buffer into ordered chunks of at most 6000 bytes', function () {
    $batcher = new DeltaBatcher;
    $text = str_repeat('é', 10000);
    $batcher->add(1, 'm1', 0, 'text', $text, 0.0);

    $chunks = $batcher->takeThread(1);

    expect(count($chunks))->toBeGreaterThan(3)
        ->and(max(array_map(fn (array $chunk): int => strlen($chunk['text']), $chunks)))->toBeLessThanOrEqual(6000)
        ->and(implode('', array_column($chunks, 'text')))->toBe($text);
});

it('tracks an open block until the thread is closed', function () {
    $batcher = new DeltaBatcher;

    expect($batcher->hasOpenBlock(1))->toBeFalse();

    $batcher->add(1, 'm1', 0, 'text', 'a', 0.0);
    $batcher->takeThread(1);
    expect($batcher->hasOpenBlock(1))->toBeTrue();

    $batcher->add(1, 'm1', 0, 'text', 'b', 0.0);
    $batcher->closeThread(1);
    expect($batcher->hasOpenBlock(1))->toBeFalse()
        ->and($batcher->due(1.0))->toBe([]);
});

it('splits a large batch into chunks with consecutive offsets', function () {
    $batcher = new DeltaBatcher;
    $text = str_repeat('a', 14000);
    $batcher->add(1, 'm1', 0, 'text', $text, 0.0);

    $chunks = $batcher->takeThread(1);

    expect(array_column($chunks, 'offset'))->toBe([0, 6000, 12000])
        ->and(implode('', array_column($chunks, 'text')))->toBe($text);
});

it('counts offsets in UTF-16 code units', function () {
    $batcher = new DeltaBatcher;
    $batcher->add(1, 'm1', 0, 'text', '😀é', 0.0);
    $batcher->takeThread(1);
    $batcher->add(1, 'm1', 0, 'text', 'x', 0.1);

    expect($batcher->takeThread(1)[0]['offset'])->toBe(3);
});

it('keys every block of one message by its content index', function () {
    $batcher = new DeltaBatcher;
    $batcher->add(1, 'msg_1', 0, 'thinking', 'a', 0.0);
    $batcher->add(1, 'msg_1', 1, 'text', 'b', 0.0);

    expect(array_column($batcher->takeThread(1), 'blockKey'))->toBe(['msg_1:0', 'msg_1:1']);
});

it('hands the open block index and first delta time to the entry until it stops', function () {
    $batcher = new DeltaBatcher;
    $batcher->startBlock(1, 'msg_1', 1);
    $batcher->add(1, 'msg_1', 1, 'text', 'a', 1700000000.5);
    $batcher->add(1, 'msg_1', 1, 'text', 'b', 1700000009.0);

    expect($batcher->takeOpenBlock(1, 'msg_1'))->toBe(['blockIndex' => 1, 'startedAt' => '2023-11-14T22:13:20+00:00'])
        ->and($batcher->takeOpenBlock(1, 'msg_2'))->toBeNull();

    $batcher->stopBlock(1, 'msg_1', 1);
    expect($batcher->takeOpenBlock(1, 'msg_1'))->toBeNull();
});

it('reports pending text', function () {
    $batcher = new DeltaBatcher;
    expect($batcher->hasPending())->toBeFalse();
    $batcher->add(1, 'm1', 0, 'text', 'a', 0.0);
    expect($batcher->hasPending())->toBeTrue();
    $batcher->takeThread(1);
    expect($batcher->hasPending())->toBeFalse();
});

it('holds no state for a thread after it is closed', function () {
    $batcher = new DeltaBatcher;
    $batcher->startMessage(1, 'm1');
    $batcher->startBlock(1, 'm1', 0);
    $batcher->add(1, 'm1', 0, 'text', 'a', 0.0);
    $batcher->takeThread(1);
    $batcher->add(1, 'm1', 0, 'text', 'b', 0.0);

    expect($batcher->hasStateFor(1))->toBeTrue();

    $batcher->closeThread(1);
    expect($batcher->hasStateFor(1))->toBeFalse();
});
