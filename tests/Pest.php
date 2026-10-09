<?php

use App\Channels\Slack\SenderPolicy;
use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Models\Repository;
use App\Services\RepositoryConfig;
use App\Services\RepositoryConfigParser;
use Carbon\CarbonImmutable;
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
    ->beforeEach(function () {
        // Slack webhook tests post as arbitrary user IDs. The sender policy
        // tests swap the real SenderPolicy back in with faked Slack lookups.
        app()->instance(SenderPolicy::class, new class extends SenderPolicy
        {
            public function isAllowed(string $userId, string ...$payloadTeamIds): bool
            {
                return true;
            }
        });

        // Most tests never touch GitHub. They see the database values, as a
        // repository without `.yak/` files does. Tests of the reader itself
        // call app()->forgetInstance(RepositoryConfig::class).
        app()->instance(RepositoryConfig::class, new class extends RepositoryConfig
        {
            public function __construct() {}

            public function snapshot(Repository $repository): ConfigSnapshot
            {
                return ConfigSnapshot::unavailable();
            }
        });
    })
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

/**
 * Serve fixed `.yak/` files to every Repository::settings() call in a test.
 *
 * @param  array<string, string>  $files  file name under .yak/ => raw content
 */
function fakeYakFiles(array $files, string $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): void
{
    $parsed = [];
    foreach ($files as $name => $content) {
        $result = (new RepositoryConfigParser)->parse($name, $content);
        expect($result['errors'])->toBe([]);
        $parsed[$name] = new ConfigFile($name, $result['data'], $content, $sha, null, null, null);
    }
    $snapshot = new ConfigSnapshot('read', $sha, CarbonImmutable::now(), null, $parsed);

    app()->instance(RepositoryConfig::class, new class($snapshot) extends RepositoryConfig
    {
        public function __construct(private ConfigSnapshot $fixedSnapshot) {}

        public function snapshot(Repository $repository): ConfigSnapshot
        {
            return $this->fixedSnapshot;
        }
    });
}

/**
 * Serve an unreachable snapshot for the named repository slugs and a clean
 * read with no files for every other repository.
 *
 * @param  list<string>  $slugs
 */
function fakeUnreachableYakRead(array $slugs): void
{
    app()->instance(RepositoryConfig::class, new class($slugs) extends RepositoryConfig
    {
        /** @param list<string> $slugs */
        public function __construct(private array $slugs) {}

        public function snapshot(Repository $repository): ConfigSnapshot
        {
            return in_array($repository->slug, $this->slugs, true)
                ? new ConfigSnapshot('unreachable', null, null, 'GitHub is down', [])
                : new ConfigSnapshot('read', str_repeat('a', 40), CarbonImmutable::now(), null, []);
        }
    });
}
