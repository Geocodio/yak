<?php

use App\Models\Repository;
use App\Models\User;
use App\Services\RiskProfileChangeCheck;
use App\Services\RiskProfileMatcher;
use App\Support\CodeOwners;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('inertia.testing.ensure_pages_exist', false);
    fakeYakFiles(['risk-profile.yml' => riskProfileYaml(), 'config.yml' => "version: 1\nco_owner_gate:\n  mode: off\n"]);
    configureFakeGitHubApp();
});

/**
 * Fake a PR's metadata, its file list and CODEOWNERS.
 *
 * @param  list<array<string, string>>  $files
 */
function fakeChangeCheckPullRequest(int $number, array $files, ?string $codeOwners = null): void
{
    Http::fake(gitHubTokenFake() + [
        "api.github.com/repos/*/pulls/{$number}/files*" => Http::response($files),
        "api.github.com/repos/*/pulls/{$number}" => Http::response(['number' => $number, 'html_url' => "https://github.com/o/r/pull/{$number}"]),
        'api.github.com/repos/*/pulls/*' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/*/contents/.github/CODEOWNERS*' => $codeOwners === null ? Http::response(['message' => 'Not Found'], 404) : Http::response($codeOwners),
        'api.github.com/repos/*/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);
}

function runChangeCheck(Repository $repository, string $input): array
{
    return app(RiskProfileChangeCheck::class)->run($repository, $input);
}

it('holds a PR that touches a high area and names the owners', function () {
    fakeChangeCheckPullRequest(4790, [['filename' => 'app/Http/Kernel.php'], ['filename' => 'tests/Feature/Api/SecurityHeadersTest.php']], "app/Http/** @mhansen\n");

    $result = runChangeCheck(Repository::factory()->create(), '#4790');

    expect($result)->toMatchArray(['verdict' => 'held', 'kind' => 'pr', 'label' => '#4790', 'asks' => ['@mhansen'], 'gateMode' => 'off', 'holdReason' => null])
        ->and($result['heldAreas'][0]['name'])->toBe('API middleware and HTTP kernel')
        ->and($result['files'][0]['path'])->toBe('app/Http/Kernel.php')
        ->and($result['url'])->toBe('https://github.com/o/r/pull/4790');
});

it('does not hold a change that matches no area', function () {
    fakeChangeCheckPullRequest(4755, [['filename' => 'app/Support/Slug.php']]);

    expect(runChangeCheck(Repository::factory()->create(), '4755'))
        ->toMatchArray(['verdict' => 'normal', 'heldAreas' => [], 'asks' => [], 'capReason' => null, 'holdReason' => null]);
});

it('matches the old path of a renamed file', function () {
    fakeChangeCheckPullRequest(5, [['filename' => 'app/Moved.php', 'previous_filename' => 'app/Http/Kernel.php', 'status' => 'renamed']]);

    $result = runChangeCheck(Repository::factory()->create(), '5');

    expect($result['verdict'])->toBe('held')
        ->and($result['files'][0])->toMatchArray(['path' => 'app/Moved.php', 'previousPath' => 'app/Http/Kernel.php']);
});

it('always holds a change to the host floor and says why', function () {
    fakeChangeCheckPullRequest(6, [['filename' => '.yak/config.yml']]);

    expect(runChangeCheck(Repository::factory()->create(), '6'))
        ->toMatchArray(['verdict' => 'held', 'isFloorHeld' => true, 'heldAreas' => [], 'holdReason' => "This change touches Yak's own config files, so Yak always holds it."]);
});

it('holds a change with files Yak could not check and says why', function () {
    fakeChangeCheckPullRequest(8, [['filename' => 'app/Support/Slug.php']]);
    app()->instance(RiskProfileMatcher::class, new class extends RiskProfileMatcher
    {
        public function classify(array $paths, array $areas, array $floorPaths, ?CodeOwners $codeOwners = null, bool $withGlobHits = false): array
        {
            return ['files' => ['app/Support/Slug.php' => ['areas' => [], 'area' => null, 'isFloor' => false, 'owners' => [], 'isUnchecked' => true, 'globHits' => []]], 'isCapped' => false];
        }
    });

    expect(runChangeCheck(Repository::factory()->create(), '8'))
        ->toMatchArray(['verdict' => 'held', 'holdReason' => 'Yak could not check some files against the profile, so it would hold this change.']);
});

it('reports a PR that does not exist', function () {
    fakeChangeCheckPullRequest(1, []);
    $repository = Repository::factory()->create(['github_full_name' => 'Geocodio/geocodio']);

    $result = runChangeCheck($repository, '#9999');

    expect($result['verdict'])->toBe('not-found')
        ->and($result['message'])->toStartWith('PR #9999 was not found in Geocodio/geocodio.');
});

it('tests a branch with slashes through compare', function () {
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/compare/main...feature%2Fheaders*' => Http::response(['files' => [['filename' => 'app/Http/Kernel.php']]]),
        'api.github.com/repos/*/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);
    $repository = Repository::factory()->create(['default_branch' => 'main']);

    expect(runChangeCheck($repository, 'feature/headers'))
        ->toMatchArray(['verdict' => 'held', 'kind' => 'branch', 'label' => 'feature/headers'])
        ->and(runChangeCheck($repository, 'feature/headers')['url'])->toEndWith('/compare/main...feature%2Fheaders');
});

it('reports a branch that does not exist', function () {
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/*/compare/*' => Http::response(['message' => 'Not Found'], 404)]);
    $repository = Repository::factory()->create(['github_full_name' => 'Geocodio/geocodio']);

    $result = runChangeCheck($repository, 'nope');

    expect($result['verdict'])->toBe('not-found')
        ->and($result['message'])->toStartWith('Branch nope was not found in Geocodio/geocodio.');
});

it('rejects empty and unsafe input', function (string $input) {
    Http::fake();

    expect(runChangeCheck(Repository::factory()->create(), $input))
        ->toMatchArray(['verdict' => 'invalid', 'message' => 'Enter a PR number or a branch name to test.']);
    Http::assertNothingSent();
})->with(['', '   ', '../main', 'feature..x', '-x', 'a b', 'x?y', '/abs', 'end/']);

it('holds a branch at the 300-file compare cap', function () {
    $files = array_map(fn (int $number): array => ['filename' => "docs/file{$number}.md"], range(1, 300));
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/compare/*' => Http::response(['files' => $files]),
        'api.github.com/repos/*/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $result = runChangeCheck(Repository::factory()->create(), 'big-branch');

    expect($result)->toMatchArray(['verdict' => 'held', 'capReason' => 'listing', 'heldAreas' => []])
        ->and($result['holdReason'])->toStartWith('GitHub compares at most 300 files')
        ->and($result['files'])->toHaveCount(200);
});

it('holds when the budget runs out', function () {
    fakeChangeCheckPullRequest(9, [['filename' => 'a.php'], ['filename' => 'b.php']]);
    app()->bind(RiskProfileMatcher::class, fn () => new RiskProfileMatcher(maxComparisons: 1));

    expect(runChangeCheck(Repository::factory()->create(), '9'))
        ->toMatchArray(['verdict' => 'held', 'capReason' => 'budget', 'holdReason' => 'Yak could not check every file against the profile in time, so it would hold this change.']);
});

it('reports a GitHub failure instead of not found', function () {
    Http::fake(gitHubTokenFake() + ['api.github.com/repos/*/pulls/7' => Http::response(['message' => 'down'], 503)]);

    expect(runChangeCheck(Repository::factory()->create(), '7'))
        ->toMatchArray(['verdict' => 'error', 'message' => 'Could not read the change from GitHub. Try again.']);
});

it('reports no profile without calling GitHub', function () {
    fakeYakFiles([]);
    Http::fake();

    expect(runChangeCheck(Repository::factory()->create(), '4790')['verdict'])->toBe('no-profile');
    Http::assertNothingSent();
});

it('serves the result as an optional prop', function () {
    fakeChangeCheckPullRequest(4790, [['filename' => 'app/Http/Kernel.php']]);
    $repository = Repository::factory()->create();
    $this->actingAs(User::factory()->create());
    $version = $this->get(route('repos.risk-profile.show', $repository), ['X-Inertia' => 'true'])->headers->get('X-Inertia-Version');

    $this->get(route('repos.risk-profile.show', ['repository' => $repository, 'change' => '4790']), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Repositories/RiskProfile',
        'X-Inertia-Partial-Data' => 'changeTest',
    ])->assertJsonPath('props.changeTest.verdict', 'held');
});

it('leaves the prop out of the initial load', function () {
    $repository = Repository::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('repos.risk-profile.show', ['repository' => $repository, 'change' => '4790']))
        ->assertInertia(fn ($page) => $page->missing('changeTest'));
});

it('classifies a file literally named 0', function () {
    fakeYakFiles(['risk-profile.yml' => "version: 1\nareas:\n  - name: Everything\n    risk: high\n    paths: [\"*\"]\n    symbols: []\n    rationale: All of it.\n    evidence: [\"x\"]\nunknowns: []\n"]);
    fakeChangeCheckPullRequest(10, [['filename' => '0']]);

    $result = runChangeCheck(Repository::factory()->create(), '10');

    expect($result['verdict'])->toBe('held')
        ->and($result['heldAreas'][0]['name'])->toBe('Everything')
        ->and($result['files'][0]['path'])->toBe('0');
});

it('returns the cap message even when a held area exists', function () {
    $files = array_map(fn (int $number): array => ['filename' => "docs/file{$number}.md"], range(1, 299));
    $files[] = ['filename' => 'app/Http/Kernel.php'];
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/compare/*' => Http::response(['files' => $files]),
        'api.github.com/repos/*/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $result = runChangeCheck(Repository::factory()->create(), 'big-branch');

    expect($result['holdReason'])->toBeNull()
        ->and($result['capMessage'])->toBe('GitHub compares at most 300 files, so Yak cannot see every file and would hold this change.');
});

it('returns the cap message for each cap reason and null otherwise', function () {
    fakeChangeCheckPullRequest(11, [['filename' => 'a.php']]);
    expect(runChangeCheck(Repository::factory()->create(), '11')['capMessage'])->toBeNull();

    app()->bind(RiskProfileMatcher::class, fn () => new RiskProfileMatcher(maxComparisons: 1));
    expect(runChangeCheck(Repository::factory()->create(), '11')['capMessage'])->toBe('Yak could not check every file against the profile in time, so it would hold this change.');
});

it('returns the cap message for a PR at the 3,000-file listing cap', function () {
    $pages = Http::sequence();
    foreach (array_chunk(range(1, 3000), 100) as $chunk) {
        $pages->push(array_map(fn (int $number): array => ['filename' => "f{$number}.md"], $chunk));
    }
    $pages->push([]);
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/*/pulls/12/files*' => $pages,
        'api.github.com/repos/*/pulls/12' => Http::response(['number' => 12]),
        'api.github.com/repos/*/contents/*' => Http::response(['message' => 'Not Found'], 404),
    ]);
    expect(runChangeCheck(Repository::factory()->create(), '12')['capMessage'])->toBe('GitHub lists at most 3,000 files for a PR, so Yak cannot see every file and would hold this change.');
});
