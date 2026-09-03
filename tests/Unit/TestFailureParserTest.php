<?php

use App\Services\TestFailureParser;

beforeEach(function () {
    $this->parser = new TestFailureParser;
});

test('parses the full Pest header with a class > description name', function () {
    $log = <<<'LOG'
    ......s.......
       FAILED  Tests\Dash\Browser\MultipleSessionsTest > it can access change password
      Timeout 30000ms exceeded.
      Tests:    1 failed, 64 passed (225 assertions)
    LOG;

    $failures = $this->parser->parse($log);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['test'])->toBe('Tests\Dash\Browser\MultipleSessionsTest > it can access change password')
        ->and($failures[0]['output'])->toContain('Timeout 30000ms exceeded.');
});

test('parses a truncated name followed by an exception class', function () {
    // Pest truncates to the terminal width, dropping the ` > description`
    // half and leaving the exception type in the trailing column.
    $log = <<<'LOG'
       FAILED  Tests\Dash\Feature\OneOffInvoiceIssuanceTest…   RateLimitException
      This object cannot be accessed right now.
      Tests:    1 failed, 7227 passed (16779 assertions)
    LOG;

    $failures = $this->parser->parse($log);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['test'])->toBe('Tests\Dash\Feature\OneOffInvoiceIssuanceTest…')
        ->and($failures[0]['output'])->toContain('cannot be accessed right now');
});

test('strips the GitHub Actions timestamp prefix from every line', function () {
    $log = implode("\n", [
        '2026-08-27T07:24:34.4244357Z    FAILED  Tests\Feature\FooTest > it works   RuntimeException',
        '2026-08-27T07:24:34.4244400Z   at app/Foo.php:12',
        '2026-08-27T07:24:35.0000000Z   Tests:    1 failed, 10 passed (20 assertions)',
    ]);

    $failures = $this->parser->parse($log);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['test'])->toBe('Tests\Feature\FooTest > it works')
        ->and($failures[0]['output'])->toContain('at app/Foo.php:12')
        ->and($failures[0]['output'])->not->toContain('2026-08-27T');
});

test('strips ANSI colour codes', function () {
    $log = "  \e[31;1mFAILED\e[39;22m  Tests\\Feature\\BarTest > it fails\n  Tests:    1 failed, 2 passed";

    $failures = $this->parser->parse($log);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]['test'])->toBe('Tests\Feature\BarTest > it fails');
});

test('ignores bare FAILED banners that carry no test identifier', function () {
    $log = <<<'LOG'
       FAILED  build step 7
       FAILED
      Process completed with exit code 1.
    LOG;

    expect($this->parser->parse($log))->toBeEmpty();
});

test('separates consecutive failures into one entry each', function () {
    $log = <<<'LOG'
       FAILED  Tests\Feature\OneTest > it does a
      first failure detail
       FAILED  Tests\Feature\TwoTest > it does b
      second failure detail
      Tests:    2 failed, 5 passed (9 assertions)
    LOG;

    $failures = $this->parser->parse($log);

    expect($failures)->toHaveCount(2)
        ->and($failures[0]['test'])->toBe('Tests\Feature\OneTest > it does a')
        ->and($failures[0]['output'])->toContain('first failure detail')
        ->and($failures[0]['output'])->not->toContain('second failure detail')
        ->and($failures[1]['test'])->toBe('Tests\Feature\TwoTest > it does b');
});

test('returns nothing for a log with no test failures', function () {
    expect($this->parser->parse("Process completed with exit code 1.\nCleaning up orphan processes"))
        ->toBeEmpty();
});
