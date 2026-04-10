<?php

use App\GitOperations;

/*
|--------------------------------------------------------------------------
| Basic Branch Name Generation
|--------------------------------------------------------------------------
*/

test('simple external id produces yak/ prefixed branch name', function () {
    expect(GitOperations::branchName('ISSUE-42'))->toBe('yak/ISSUE-42');
});

test('linear-style issue id is preserved', function () {
    expect(GitOperations::branchName('ENG-123'))->toBe('yak/ENG-123');
});

test('numeric external id is preserved', function () {
    expect(GitOperations::branchName('12345'))->toBe('yak/12345');
});

test('dots in external id are preserved', function () {
    expect(GitOperations::branchName('1234567890.123456'))->toBe('yak/1234567890.123456');
});

test('slashes in external id are preserved', function () {
    expect(GitOperations::branchName('feature/abc'))->toBe('yak/feature/abc');
});

/*
|--------------------------------------------------------------------------
| Special Character Handling
|--------------------------------------------------------------------------
*/

test('spaces are replaced with hyphens', function () {
    expect(GitOperations::branchName('fix auth bug'))->toBe('yak/fix-auth-bug');
});

test('tilde is replaced', function () {
    expect(GitOperations::branchName('test~1'))->toBe('yak/test-1');
});

test('caret is replaced', function () {
    expect(GitOperations::branchName('HEAD^2'))->toBe('yak/HEAD-2');
});

test('colon is replaced', function () {
    expect(GitOperations::branchName('scope:fix'))->toBe('yak/scope-fix');
});

test('question mark is replaced', function () {
    expect(GitOperations::branchName('what?'))->toBe('yak/what');
});

test('asterisk is replaced', function () {
    expect(GitOperations::branchName('glob*match'))->toBe('yak/glob-match');
});

test('square brackets are replaced', function () {
    expect(GitOperations::branchName('array[0]'))->toBe('yak/array-0');
});

test('backslash is replaced', function () {
    expect(GitOperations::branchName('path\\to\\file'))->toBe('yak/path-to-file');
});

test('at-brace sequence is handled', function () {
    expect(GitOperations::branchName('ref@{upstream}'))->toBe('yak/ref-upstream');
});

test('multiple special characters are replaced', function () {
    expect(GitOperations::branchName('fix: auth [bug] #123'))->toBe('yak/fix-auth-bug-123');
});

/*
|--------------------------------------------------------------------------
| Consecutive Character Collapsing
|--------------------------------------------------------------------------
*/

test('consecutive dots are collapsed to single dot', function () {
    expect(GitOperations::branchName('a..b'))->toBe('yak/a.b');
});

test('consecutive hyphens from replacements are collapsed', function () {
    expect(GitOperations::branchName('a::b'))->toBe('yak/a-b');
});

test('consecutive slashes are collapsed', function () {
    expect(GitOperations::branchName('a//b'))->toBe('yak/a/b');
});

/*
|--------------------------------------------------------------------------
| Leading/Trailing Character Trimming
|--------------------------------------------------------------------------
*/

test('leading hyphens are trimmed', function () {
    expect(GitOperations::branchName('-issue'))->toBe('yak/issue');
});

test('trailing hyphens are trimmed', function () {
    expect(GitOperations::branchName('issue-'))->toBe('yak/issue');
});

test('leading dots are trimmed', function () {
    expect(GitOperations::branchName('.hidden'))->toBe('yak/hidden');
});

test('trailing dots are trimmed', function () {
    expect(GitOperations::branchName('issue.'))->toBe('yak/issue');
});

/*
|--------------------------------------------------------------------------
| .lock Suffix Handling
|--------------------------------------------------------------------------
*/

test('dot lock suffix is removed', function () {
    expect(GitOperations::branchName('branch.lock'))->toBe('yak/branch');
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

test('empty string produces fallback branch name', function () {
    expect(GitOperations::branchName(''))->toBe('yak/task');
});

test('string of only special characters produces fallback', function () {
    expect(GitOperations::branchName('***'))->toBe('yak/task');
});

test('complex real-world slack thread id is preserved', function () {
    expect(GitOperations::branchName('1712345678.123456'))->toBe('yak/1712345678.123456');
});

test('sentry issue with hash is handled', function () {
    expect(GitOperations::branchName('SENTRY-ABC#42'))->toBe('yak/SENTRY-ABC-42');
});

test('unicode characters are preserved', function () {
    $result = GitOperations::branchName('fix-bug-42');
    expect($result)->toBe('yak/fix-bug-42');
});
