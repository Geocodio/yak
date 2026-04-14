<?php

use App\Services\AiPricing;
use Laravel\Ai\Responses\Data\Usage;

test('computes cost for a known model', function () {
    // claude-haiku-4-5: input $1/M, output $5/M, cache_write $1.25/M, cache_read $0.10/M
    $usage = new Usage(
        promptTokens: 1_000_000,        // includes cache read + write below
        completionTokens: 500_000,
        cacheWriteInputTokens: 100_000,
        cacheReadInputTokens: 200_000,
    );

    $cost = AiPricing::cost('anthropic', 'claude-haiku-4-5-20251001', $usage);

    // plain input = 700_000 / 1M * 1.00 = 0.70
    // output      = 500_000 / 1M * 5.00 = 2.50
    // cache_write = 100_000 / 1M * 1.25 = 0.125
    // cache_read  = 200_000 / 1M * 0.10 = 0.02
    // total = 3.345
    expect($cost)->toBe(3.345);
});

test('counts reasoning tokens at output rate', function () {
    $usage = new Usage(
        promptTokens: 0,
        completionTokens: 0,
        reasoningTokens: 1_000_000,
    );

    $cost = AiPricing::cost('anthropic', 'claude-haiku-4-5-20251001', $usage);

    expect($cost)->toBe(5.0);
});

test('returns zero and logs for unknown model', function () {
    $usage = new Usage(promptTokens: 1000, completionTokens: 500);

    $cost = AiPricing::cost('anthropic', 'claude-unknown-model', $usage);

    expect($cost)->toBe(0.0);
});

test('handles zero usage', function () {
    $cost = AiPricing::cost('anthropic', 'claude-haiku-4-5-20251001', new Usage);

    expect($cost)->toBe(0.0);
});
