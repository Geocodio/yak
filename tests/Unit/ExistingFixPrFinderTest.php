<?php

use App\DataTransferObjects\PullRequestCandidate;
use App\Services\ExistingFixPrFinder;

function candidate(array $files, string $title = 'Some change', string $body = '', bool $merged = false): PullRequestCandidate
{
    return new PullRequestCandidate(
        number: 1,
        title: $title,
        body: $body,
        url: 'https://github.com/acme/widgets/pull/1',
        changedFiles: $files,
        isMerged: $merged,
    );
}

function finder(): ExistingFixPrFinder
{
    return app(ExistingFixPrFinder::class);
}

test('matches a pull request that changes the test class file', function () {
    $match = finder()->match(
        collect([candidate(['tests/Api/Integration/Common/AccuracyScoreAPITest.php'])]),
        'Tests\Api\Integration\Common\AccuracyScoreAPITest',
    );

    expect($match)->not->toBeNull();
    expect($match->reason)->toBe('changes tests/Api/Integration/Common/AccuracyScoreAPITest.php');
});

test('does not match a same-named test in a different namespace', function () {
    $match = finder()->match(
        collect([candidate(['tests/Billing/UserTest.php'])]),
        'Tests\Accounts\UserTest',
    );

    expect($match)->toBeNull();
});

test('falls back to the file basename only when the class name was truncated', function () {
    $candidates = collect([candidate(['tests/Billing/UserTest.php'])]);

    expect(finder()->match($candidates, 'Tests\Accounts\UserTest', classWasTruncated: false))->toBeNull();

    $match = finder()->match($candidates, 'Tests\Accounts\UserTest', classWasTruncated: true);
    expect($match)->not->toBeNull();
    expect($match->reason)->toBe('changes a file named UserTest.php');
});

test('matches when the pull request title names the test class', function () {
    $match = finder()->match(
        collect([candidate(['src/Helper.php'], title: 'Fix AccuracyScoreAPITest after the rename')]),
        'Tests\Api\AccuracyScoreAPITest',
    );

    expect($match)->not->toBeNull();
    expect($match->reason)->toBe('title names AccuracyScoreAPITest');
});

test('matches when the pull request description names the test class', function () {
    $match = finder()->match(
        collect([candidate(['src/Helper.php'], body: 'This also repairs AccuracyScoreAPITest.')]),
        'Tests\Api\AccuracyScoreAPITest',
    );

    expect($match)->not->toBeNull();
    expect($match->reason)->toBe('description names AccuracyScoreAPITest');
});

test('returns null when nothing matches', function () {
    $match = finder()->match(
        collect([candidate(['src/Helper.php'], title: 'Unrelated work')]),
        'Tests\Api\AccuracyScoreAPITest',
    );

    expect($match)->toBeNull();
});

test('a class with no namespace cannot be path matched', function () {
    $match = finder()->match(
        collect([candidate(['tests/UserTest.php'])]),
        'UserTest',
    );

    expect($match)->toBeNull();
});
