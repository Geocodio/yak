<?php

use App\GitOperations;

test('returns base name when remote has no collision', function () {
    $name = GitOperations::resolveAvailableBranchName(
        'yak/ISSUE-42',
        fn (string $candidate) => false,
    );

    expect($name)->toBe('yak/ISSUE-42');
});

test('appends -2 when base name exists on remote', function () {
    $existing = ['yak/ISSUE-42'];

    $name = GitOperations::resolveAvailableBranchName(
        'yak/ISSUE-42',
        fn (string $candidate) => in_array($candidate, $existing, true),
    );

    expect($name)->toBe('yak/ISSUE-42-2');
});

test('increments counter until a free name is found', function () {
    $existing = ['yak/ISSUE-42', 'yak/ISSUE-42-2', 'yak/ISSUE-42-3'];

    $name = GitOperations::resolveAvailableBranchName(
        'yak/ISSUE-42',
        fn (string $candidate) => in_array($candidate, $existing, true),
    );

    expect($name)->toBe('yak/ISSUE-42-4');
});

test('starts counter at 2 even when -1 is taken', function () {
    $existing = ['yak/task', 'yak/task-1'];

    $name = GitOperations::resolveAvailableBranchName(
        'yak/task',
        fn (string $candidate) => in_array($candidate, $existing, true),
    );

    expect($name)->toBe('yak/task-2');
});

test('skips gaps and returns first unused suffix', function () {
    $existing = ['yak/abc', 'yak/abc-2'];

    $name = GitOperations::resolveAvailableBranchName(
        'yak/abc',
        fn (string $candidate) => in_array($candidate, $existing, true),
    );

    expect($name)->toBe('yak/abc-3');
});

test('throws when max attempts exhausted', function () {
    GitOperations::resolveAvailableBranchName(
        'yak/stuck',
        fn (string $candidate) => true,
        maxAttempts: 3,
    );
})->throws(RuntimeException::class, "Unable to find an available branch name for 'yak/stuck'");

test('only calls existence check as many times as needed', function () {
    $calls = [];

    $name = GitOperations::resolveAvailableBranchName(
        'yak/foo',
        function (string $candidate) use (&$calls) {
            $calls[] = $candidate;

            return $candidate === 'yak/foo';
        },
    );

    expect($name)->toBe('yak/foo-2')
        ->and($calls)->toBe(['yak/foo', 'yak/foo-2']);
});
