<?php

use App\Services\Threads\TurnResult;

it('reads the result text and cost from a success event', function () {
    $result = TurnResult::fromEvent(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'All done', 'session_id' => 's1'], 4, 1500, 0.25);

    expect($result->isError)->toBeFalse()
        ->and($result->resultSummary)->toBe('All done')
        ->and($result->numTurns)->toBe(4)
        ->and($result->durationMs)->toBe(1500)
        ->and($result->costUsd)->toBe(0.25);
});

it('fails when the turn recorded no result event', function () {
    $result = TurnResult::fromEvent(null, 0, 0, 0.0);

    expect($result->isError)->toBeTrue();
});

it('marks an error result', function () {
    $result = TurnResult::fromEvent(['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'boom'], 1, 10, 0.0);

    expect($result->isError)->toBeTrue()->and($result->errorSubtype)->toBe('error_during_execution');
});
