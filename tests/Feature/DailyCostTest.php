<?php

use App\Models\DailyCost;

test('a new task adds its cost and counts once', function () {
    DailyCost::accumulate(1.25);
    DailyCost::accumulate(0.75);

    $today = DailyCost::whereDate('date', now()->toDateString())->sole();

    expect((float) $today->total_usd)->toBe(2.0)
        ->and($today->task_count)->toBe(2);
});

test('a retry or clarification reply adds cost without counting another task', function () {
    DailyCost::accumulate(1.0);
    DailyCost::accumulate(0.5, newTask: false);
    DailyCost::accumulate(0.25, newTask: false);

    $today = DailyCost::whereDate('date', now()->toDateString())->sole();

    expect((float) $today->total_usd)->toBe(1.75)
        ->and($today->task_count)->toBe(1);
});

test('the first run of a day that is not a new task still creates the row', function () {
    DailyCost::accumulate(0.5, newTask: false);

    $today = DailyCost::whereDate('date', now()->toDateString())->sole();

    expect((float) $today->total_usd)->toBe(0.5)
        ->and($today->task_count)->toBe(0);
});
