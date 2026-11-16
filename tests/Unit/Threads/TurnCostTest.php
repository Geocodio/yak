<?php

use App\Services\Threads\TurnCost;

it('sums costUSD from model_usage_since_last_result', function () {
    $result = ['total_cost_usd' => 9.0, 'model_usage_since_last_result' => [
        'a' => ['costUSD' => 0.25], 'b' => ['costUSD' => 0.5],
    ]];

    expect(TurnCost::deltaUsd($result, 1.0))->toBe(0.75);
});

it('subtracts the recorded session total when since-last-result is empty', function () {
    $result = ['total_cost_usd' => 1.5, 'model_usage_since_last_result' => []];

    expect(TurnCost::deltaUsd($result, 1.0))->toBe(0.5);
    expect(TurnCost::deltaUsd(['total_cost_usd' => 1.5], 1.0))->toBe(0.5);
});

it('treats a lower total as a reused session id', function () {
    expect(TurnCost::deltaUsd(['total_cost_usd' => 0.2], 1.0))->toBe(0.2);
});

it('returns zero when neither source is present', function () {
    expect(TurnCost::deltaUsd([], 1.0))->toBe(0.0);
    expect(TurnCost::sessionTotalUsd([], 1.0))->toBe(1.0);
    expect(TurnCost::sessionTotalUsd(['total_cost_usd' => 2.0], 1.0))->toBe(2.0);
});

it('reads tokens from top-level usage', function () {
    $result = ['usage' => [
        'input_tokens' => 1, 'output_tokens' => 2,
        'cache_read_input_tokens' => 3, 'cache_creation_input_tokens' => 4,
    ]];

    expect(TurnCost::tokens($result))->toBe(['input' => 1, 'output' => 2, 'cache_read' => 3, 'cache_creation' => 4]);
});

it('falls back to modelUsage tokens when top-level usage is all zero', function () {
    $result = [
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        'modelUsage' => [
            'a' => ['inputTokens' => 1, 'outputTokens' => 2, 'cacheReadInputTokens' => 3, 'cacheCreationInputTokens' => 4],
            'b' => ['inputTokens' => 10, 'outputTokens' => 20, 'cacheReadInputTokens' => 30, 'cacheCreationInputTokens' => 40],
        ],
    ];

    expect(TurnCost::tokens($result))->toBe(['input' => 11, 'output' => 22, 'cache_read' => 33, 'cache_creation' => 44]);
    expect(TurnCost::tokens([]))->toBe(['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0]);
});

it('sums per-result deltas to the final session total on recorded fixtures', function (string $fixture, bool $stripSinceLastResult) {
    $results = array_values(array_filter(runnerStreamLines($fixture), fn (array $line) => ($line['type'] ?? null) === 'result'));
    expect($results)->not->toBeEmpty();

    $recorded = 0.0;
    $sum = 0.0;

    foreach ($results as $result) {
        if ($stripSinceLastResult) {
            $result['model_usage_since_last_result'] = [];
        }

        $sum += TurnCost::deltaUsd($result, $recorded);
        $recorded = TurnCost::sessionTotalUsd($result, $recorded);
    }

    expect($sum)->toEqualWithDelta(end($results)['total_cost_usd'], 0.000001);
})->with([
    ['natural-turn-end', false],
    ['natural-turn-end', true],
    ['background-bash-wake-result', false],
    ['background-bash-wake-result', true],
]);
