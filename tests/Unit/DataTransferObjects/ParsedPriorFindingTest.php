<?php

use App\DataTransferObjects\ParsedPriorFinding;

it('builds from a structurer array', function () {
    $finding = ParsedPriorFinding::fromArray([
        'id' => 42,
        'status' => 'fixed',
        'reply_body' => 'Fixed in c0ffee1.',
    ]);

    expect($finding->commentId)->toBe(42)
        ->and($finding->status)->toBe('fixed')
        ->and($finding->replyBody)->toBe('Fixed in c0ffee1.');
});

it('treats missing reply_body as empty string', function () {
    $finding = ParsedPriorFinding::fromArray([
        'id' => 42,
        'status' => 'untouched',
    ]);

    expect($finding->replyBody)->toBe('');
});

it('throws on missing required keys', function () {
    ParsedPriorFinding::fromArray(['id' => 1]);
})->throws(RuntimeException::class, 'Prior finding missing required key: status');

it('throws on invalid status', function () {
    ParsedPriorFinding::fromArray(['id' => 1, 'status' => 'maybe', 'reply_body' => '']);
})->throws(RuntimeException::class, "Invalid prior-finding status 'maybe'");
