<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Contract');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Test helpers are loaded from the tests/Helpers directory. These provide
| reusable functions for common test operations like faking Claude CLI
| responses and asserting external API interactions.
|
*/

require_once __DIR__ . '/Helpers/ClaudeHelpers.php';
require_once __DIR__ . '/Helpers/AssertionHelpers.php';

/**
 * The card's description is a stretched link (its `::after` pseudo-element
 * covers the whole card) so tapping anywhere non-interactive on the card
 * opens the task -- exactly what a real click does, since the browser hit
 * -tests to the topmost element at the click point. This plugin's `click()`
 * refuses that on purpose (it verifies the target itself, not something
 * covering it, receives the event), so this forces a real click at the
 * selector's coordinates to prove the overlay behaves the way a user's tap
 * would.
 */
function forceClick(AwaitableWebpage $page, string $selector): void
{
    $page->page()->locator($selector)->click(['force' => true]);
}
