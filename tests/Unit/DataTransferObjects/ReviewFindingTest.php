<?php

use App\DataTransferObjects\ReviewFinding;

it('builds from a structurer array with a multi-line range', function () {
    $finding = ReviewFinding::fromArray([
        'file' => 'tests/Foo.php',
        'line' => 140,
        'start_line' => 138,
        'severity' => 'should_fix',
        'category' => 'Test Quality',
        'body' => "...\n\n```suggestion\nA\nB\nC\n```",
        'suggestion_loc' => 3,
    ]);

    expect($finding->line)->toBe(140)
        ->and($finding->startLine)->toBe(138)
        ->and($finding->suggestionLoc)->toBe(3);
});

it('drops start_line when it is not strictly less than line', function () {
    $equal = ReviewFinding::fromArray([
        'file' => 'tests/Foo.php', 'line' => 12, 'start_line' => 12,
        'severity' => 'consider', 'category' => 'X', 'body' => '...',
    ]);

    $inverted = ReviewFinding::fromArray([
        'file' => 'tests/Foo.php', 'line' => 12, 'start_line' => 15,
        'severity' => 'consider', 'category' => 'X', 'body' => '...',
    ]);

    expect($equal->startLine)->toBeNull()
        ->and($inverted->startLine)->toBeNull();
});

it('defaults start_line to null when omitted', function () {
    $finding = ReviewFinding::fromArray([
        'file' => 'tests/Foo.php', 'line' => 12,
        'severity' => 'consider', 'category' => 'X', 'body' => '...',
    ]);

    expect($finding->startLine)->toBeNull();
});

it('throws when a required key is missing', function () {
    ReviewFinding::fromArray(['file' => 'x', 'line' => 1, 'severity' => 'consider', 'category' => 'X']);
})->throws(RuntimeException::class, 'Finding missing required key: body');
