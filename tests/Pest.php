<?php

use App\Channels\Slack\SenderPolicy;
use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Models\Repository;
use App\Providers\ChannelServiceProvider;
use App\Services\RepositoryConfig;
use App\Services\RepositoryConfigParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

/**
 * Configure the GitHub App and fake the API calls ConfigPullRequests makes for acme/api.
 * Pass a status to make the pull request POST fail instead.
 */
function fakeGithubConfigPullRequestApi(int $pullRequestStatus = 201, array $overrides = []): void
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);

    Http::fake($overrides + [
        'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/repos/acme/api/branches/main' => Http::response(['commit' => ['sha' => 'base', 'commit' => ['tree' => ['sha' => 'tree']]]]),
        'api.github.com/repos/acme/api/git/blobs' => Http::response(['sha' => 'blob'], 201),
        'api.github.com/repos/acme/api/git/trees' => Http::response(['sha' => 'tree2'], 201),
        'api.github.com/repos/acme/api/git/commits' => Http::response(['sha' => 'commit'], 201),
        'api.github.com/repos/acme/api/git/refs' => Http::response(['ref' => 'x'], 201),
        'api.github.com/repos/acme/api/git/refs/heads/*' => Http::response(['ref' => 'x']),
        'api.github.com/repos/acme/api/pulls?*' => Http::response([]),
        'api.github.com/repos/acme/api/pulls' => $pullRequestStatus === 201
            ? Http::response(['number' => 12, 'html_url' => 'https://github.com/acme/api/pull/12'], 201)
            : Http::response(['message' => 'boom'], $pullRequestStatus),
        'api.github.com/repos/acme/api/issues/12/labels' => Http::response([]),
    ]);
}

/**
 * Serve a fixed snapshot to every RepositoryConfig::snapshot() call in a test.
 */
function bindConfigSnapshot(ConfigSnapshot $snapshot): void
{
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
 * Give the GitHub App a real signing key, an app id and an installation id,
 * so AppService can mint installation tokens against Http::fake().
 */
function configureFakeGitHubApp(): void
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);
}

/**
 * The installation-token stub. Register it before any catch-all stub, or the
 * token request gets the catch-all response.
 *
 * @return array<string, mixed>
 */
function gitHubTokenFake(): array
{
    return ['api.github.com/app/installations/*/access_tokens' => Http::response([
        'token' => 'ghs_token',
        'expires_at' => now()->addHour()->toIso8601String(),
    ])];
}

/**
 * A two-area risk profile: both areas are high, the second matches no file in
 * the usual fake tree, and one question is open.
 */
function riskProfileYaml(): string
{
    return <<<'YAML'
version: 1
areas:
  - name: API middleware and HTTP kernel
    risk: high
    paths: ["app/Http/Middleware/**", "app/Http/Kernel.php"]
    symbols: []
    rationale: Every request passes through these.
    evidence: ["Kernel registers global middleware"]
  - name: Legacy geocoder shim
    risk: high
    paths: ["lib/legacy-shim/**"]
    symbols: []
    rationale: Old code.
    evidence: ["git log"]
unknowns: ["Does launcher/ touch production credentials?"]
YAML;
}

/**
 * Fake the GitHub calls behind the risk map: the head tree, CODEOWNERS, the
 * last commit of the profile file and the pull request that merged it.
 *
 * `$pullRequests` maps a PR number to its changed files, or to an HTTP status
 * the PR answers with; `$branches` maps a branch name to its compare files.
 * Any other PR or branch is not found.
 *
 * @param  list<string>  $paths
 * @param  array<int, list<array<string, string>>|int>  $pullRequests
 * @param  array<string, list<array<string, string>>>  $branches
 */
function fakeRiskMapGitHub(array $paths, ?string $codeOwners = null, bool $isTruncated = false, int $treeStatus = 200, array $pullRequests = [], array $branches = []): void
{
    $changeFakes = [];
    foreach ($pullRequests as $number => $files) {
        if (is_int($files)) {
            $changeFakes["api.github.com/repos/*/pulls/{$number}*"] = Http::response(['message' => 'Error'], $files);

            continue;
        }
        $changeFakes["api.github.com/repos/*/pulls/{$number}/files*"] = Http::response($files);
        $changeFakes["api.github.com/repos/*/pulls/{$number}"] = Http::response(['number' => $number, 'html_url' => "https://github.com/o/r/pull/{$number}"]);
    }
    foreach ($branches as $branch => $files) {
        $changeFakes['api.github.com/repos/*/compare/*...' . rawurlencode($branch) . '*'] = Http::response(['files' => $files]);
    }
    $changeFakes['api.github.com/repos/*/pulls/*'] = Http::response(['message' => 'Not Found'], 404);
    $changeFakes['api.github.com/repos/*/compare/*'] = Http::response(['message' => 'Not Found'], 404);

    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + $changeFakes + [
        'api.github.com/repos/*/git/trees/*' => Http::response(['truncated' => $isTruncated, 'tree' => array_map(fn (string $path): array => ['path' => $path, 'type' => 'blob'], $paths)], $treeStatus),
        'api.github.com/repos/*/contents/.github/CODEOWNERS*' => $codeOwners === null ? Http::response(['message' => 'Not Found'], 404) : fn () => Http::response($codeOwners),
        'api.github.com/repos/*/contents/*' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/*/commits/*/pulls' => Http::response([['number' => 4801, 'title' => 'Add profile', 'html_url' => 'https://github.com/o/r/pull/4801', 'merged_at' => '2026-10-09T10:00:00Z']]),
        'api.github.com/repos/*/commits*' => Http::response([['sha' => str_repeat('b', 40), 'commit' => ['author' => ['name' => 'Mathias Hansen', 'date' => '2026-10-09T10:00:00Z']]]]),
        '*' => Http::response([], 500),
    ]);
}

/**
 * Fake a GitHub with no open pull request on any branch.
 */
function fakeNoRiskProfilePullRequest(): void
{
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/*/pulls*' => Http::response([])]);
}

/** @return list<string> */
function riskMapPaths(): array
{
    return array_merge(
        array_map(fn ($i) => "app/Http/Middleware/M{$i}.php", range(1, 30)),
        array_map(fn ($i) => "app/Support/S{$i}.php", range(1, 50)),
        array_map(fn ($i) => "tests/Feature/T{$i}Test.php", range(1, 80)),
        ['app/Http/Kernel.php', '.yak/config.yml', 'README.md'],
    );
}

function riskMapProfileYaml(): string
{
    return <<<'YAML'
version: 1
areas:
  - name: API middleware and HTTP kernel
    risk: high
    paths: ["app/Http/Middleware/**", "app/Http/Kernel.php"]
    symbols: []
    rationale: Every request passes through these.
    evidence: ["Kernel registers global middleware"]
  - name: Support helpers
    risk: low
    paths: ["app/Support/**"]
    symbols: []
    rationale: Small shared helpers.
    evidence: ["Helpers only"]
unknowns: []
YAML;
}

/**
 * Sign a Slack webhook payload using the v0:timestamp:body format.
 *
 * @return array{HTTP_X-Slack-Request-Timestamp: string, HTTP_X-Slack-Signature: string}
 */
function signSlackInteractivePayload(string $body, string $secret): array
{
    $timestamp = (string) time();
    $basestring = "v0:{$timestamp}:{$body}";
    $signature = 'v0=' . hash_hmac('sha256', $basestring, $secret);

    return [
        'HTTP_X-Slack-Request-Timestamp' => $timestamp,
        'HTTP_X-Slack-Signature' => $signature,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ];
}

function enableSlackForInteractive(): string
{
    $secret = 'test-interactive-secret';

    config()->set('yak.channels.slack', [
        'driver' => 'slack',
        'bot_token' => 'xoxb-test',
        'signing_secret' => $secret,
    ]);

    (new ChannelServiceProvider(app()))->boot();

    return $secret;
}
