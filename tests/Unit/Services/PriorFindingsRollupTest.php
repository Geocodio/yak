<?php

use App\DataTransferObjects\ParsedPriorFinding;
use App\Services\PriorFindingsRollup;

it('builds the rollup line from a mix of statuses', function () {
    $rollup = (new PriorFindingsRollup)->render([
        new ParsedPriorFinding(1, 'fixed', 'a'),
        new ParsedPriorFinding(2, 'fixed', 'b'),
        new ParsedPriorFinding(3, 'fixed', 'c'),
        new ParsedPriorFinding(4, 'still_outstanding', 'd'),
        new ParsedPriorFinding(5, 'untouched', ''),
        new ParsedPriorFinding(6, 'untouched', ''),
    ]);

    expect($rollup)->toBe(
        '**Status of prior findings:** 3 fixed, 1 still outstanding, 2 untouched. See thread replies for detail.',
    );
});

it('renders "All prior concerns addressed" when everything is fixed', function () {
    $rollup = (new PriorFindingsRollup)->render([
        new ParsedPriorFinding(1, 'fixed', 'a'),
        new ParsedPriorFinding(2, 'fixed', 'b'),
    ]);

    expect($rollup)->toBe('**Status of prior findings:** All prior concerns addressed.');
});

it('returns empty string when there are no prior findings', function () {
    expect((new PriorFindingsRollup)->render([]))->toBe('');
});

it('counts withdrawn separately', function () {
    $rollup = (new PriorFindingsRollup)->render([
        new ParsedPriorFinding(1, 'fixed', 'a'),
        new ParsedPriorFinding(2, 'withdrawn', 'b'),
    ]);

    expect($rollup)->toBe(
        '**Status of prior findings:** 1 fixed, 1 withdrawn. See thread replies for detail.',
    );
});
