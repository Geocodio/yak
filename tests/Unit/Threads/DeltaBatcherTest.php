<?php

use App\Services\Threads\DeltaBatcher;

it('batches deltas of one block for 50 ms', function () {
    $batcher = new DeltaBatcher;
    $batcher->add(1, 'm1:0', 'text', 'Hel', 0.000);
    $batcher->add(1, 'm1:0', 'text', 'lo ', 0.020);
    $batcher->add(1, 'm1:0', 'text', 'there', 0.040);

    expect($batcher->due(0.045))->toBe([])
        ->and($batcher->due(0.051))->toBe([['threadId' => 1, 'blockKey' => 'm1:0', 'kind' => 'text', 'text' => 'Hello there']])
        ->and($batcher->due(0.200))->toBe([]);
});

it('hands over everything buffered for one thread early', function () {
    $batcher = new DeltaBatcher;
    $batcher->add(1, 'm1:0', 'text', 'a', 0.0);
    $batcher->add(2, 'm2:0', 'thinking', 'b', 0.0);

    expect($batcher->takeThread(1))->toBe([['threadId' => 1, 'blockKey' => 'm1:0', 'kind' => 'text', 'text' => 'a']])
        ->and($batcher->takeThread(1))->toBe([])
        ->and($batcher->due(1.0))->toHaveCount(1);
});

it('splits a 20 KB buffer into ordered chunks of at most 6000 bytes', function () {
    $batcher = new DeltaBatcher;
    $text = str_repeat('é', 10000);
    $batcher->add(1, 'm1:0', 'text', $text, 0.0);

    $chunks = $batcher->takeThread(1);

    expect(count($chunks))->toBeGreaterThan(3)
        ->and(max(array_map(fn (array $chunk): int => strlen($chunk['text']), $chunks)))->toBeLessThanOrEqual(6000)
        ->and(implode('', array_column($chunks, 'text')))->toBe($text);
});

it('tracks an open block until the thread is closed', function () {
    $batcher = new DeltaBatcher;

    expect($batcher->hasOpenBlock(1))->toBeFalse();

    $batcher->add(1, 'm1:0', 'text', 'a', 0.0);
    $batcher->takeThread(1);
    expect($batcher->hasOpenBlock(1))->toBeTrue();

    $batcher->add(1, 'm1:0', 'text', 'b', 0.0);
    $batcher->closeThread(1);
    expect($batcher->hasOpenBlock(1))->toBeFalse()
        ->and($batcher->due(1.0))->toBe([]);
});
