<?php

use App\DataTransferObjects\ReviewFinding;

it('builds from a structurer array', function () {
    $finding = ReviewFinding::fromArray([
        'file' => 'tests/Foo.php',
        'line' => '140',
        'severity' => 'should_fix',
        'category' => 'Test Quality',
        'body' => "...\n\n```original\nX\n```\n```suggestion\nA\n```",
    ]);

    expect($finding->line)->toBe(140)
        ->and($finding->file)->toBe('tests/Foo.php')
        ->and($finding->body)->toContain('```original');
});

it('throws when a required key is missing', function () {
    ReviewFinding::fromArray(['file' => 'x', 'line' => 1, 'severity' => 'consider', 'category' => 'X']);
})->throws(RuntimeException::class, 'Finding missing required key: body');
