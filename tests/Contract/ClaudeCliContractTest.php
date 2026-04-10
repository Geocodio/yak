<?php

use Illuminate\Support\Facades\Process;

uses()->group('contract');

/*
|--------------------------------------------------------------------------
| Claude CLI JSON Output Schema
|--------------------------------------------------------------------------
*/

it('returns valid JSON with expected schema from Claude CLI', function () {
    $result = Process::timeout(120)->run(
        'claude -p "Respond with: hello" --dangerously-skip-permissions --bare --output-format json --max-turns 1 --max-budget-usd 0.05'
    );

    expect($result->successful())->toBeTrue();

    $output = trim($result->output());
    $decoded = json_decode($output, true);

    expect($decoded)->toBeArray()
        ->toHaveKey('result')
        ->toHaveKey('cost_usd')
        ->toHaveKey('session_id')
        ->toHaveKey('num_turns')
        ->toHaveKey('duration_ms')
        ->toHaveKey('is_error');

    expect($decoded['session_id'])->toBeString()->not->toBeEmpty();
    expect($decoded['cost_usd'])->toBeNumeric();
    expect($decoded['num_turns'])->toBeInt()->toBeGreaterThanOrEqual(1);
    expect($decoded['duration_ms'])->toBeInt()->toBeGreaterThanOrEqual(0);
    expect($decoded['is_error'])->toBeBool();
});

/*
|--------------------------------------------------------------------------
| --resume Flag
|--------------------------------------------------------------------------
*/

it('supports --resume flag to continue a session', function () {
    $firstRun = Process::timeout(120)->run(
        'claude -p "Say exactly: first" --dangerously-skip-permissions --bare --output-format json --max-turns 1 --max-budget-usd 0.05'
    );

    expect($firstRun->successful())->toBeTrue();

    $firstOutput = json_decode(trim($firstRun->output()), true);
    expect($firstOutput)->toBeArray()->toHaveKey('session_id');

    $sessionId = $firstOutput['session_id'];

    $resumeRun = Process::timeout(120)->run(
        sprintf(
            'claude -p "Say exactly: resumed" --resume %s --dangerously-skip-permissions --bare --output-format json --max-turns 1 --max-budget-usd 0.05',
            escapeshellarg($sessionId),
        )
    );

    expect($resumeRun->successful())->toBeTrue();

    $resumeOutput = json_decode(trim($resumeRun->output()), true);
    expect($resumeOutput)->toBeArray()
        ->toHaveKey('result')
        ->toHaveKey('session_id');
});

/*
|--------------------------------------------------------------------------
| --max-budget-usd Respect
|--------------------------------------------------------------------------
*/

it('respects --max-budget-usd flag by keeping cost within budget', function () {
    $budget = 0.10;

    $result = Process::timeout(120)->run(
        sprintf(
            'claude -p "Respond with: budget test" --dangerously-skip-permissions --bare --output-format json --max-turns 2 --max-budget-usd %s',
            number_format($budget, 2, '.', ''),
        )
    );

    expect($result->successful())->toBeTrue();

    $output = json_decode(trim($result->output()), true);
    expect($output)->toBeArray()->toHaveKey('cost_usd');
    expect((float) $output['cost_usd'])->toBeLessThanOrEqual($budget * 1.5); // Allow small overhead margin
});
