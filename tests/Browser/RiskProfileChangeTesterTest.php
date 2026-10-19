<?php

use App\Models\Repository;
use App\Models\User;

function changeTesterProfileYaml(): string
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
  - name: Slug helpers
    risk: low
    paths: ["app/Slug/**"]
    symbols: []
    rationale: Small helpers.
    evidence: ["Helpers only"]
unknowns: []
YAML;
}

/**
 * @param  array<int, list<array<string, string>>|int>  $pullRequests
 * @param  array<string, list<array<string, string>>>  $branches
 */
function setUpChangeTester(array $pullRequests = [], array $branches = [], string $gateMode = 'off'): Repository
{
    $repository = Repository::factory()->create(['github_full_name' => 'Geocodio/geocodio']);
    fakeYakFiles(['risk-profile.yml' => changeTesterProfileYaml(), 'config.yml' => "version: 1\nco_owner_gate:\n  mode: {$gateMode}\n"]);
    fakeRiskMapGitHub(['app/Http/Kernel.php', 'app/Support/Slug.php', 'app/Slug/Slug.php', '.yak/config.yml'], "app/Http/** @mhansen\n", pullRequests: $pullRequests, branches: $branches);
    test()->actingAs(User::factory()->create());

    return $repository;
}

function testChange($page, string $input)
{
    return $page->fill('[data-testid="change-test-input"]', $input)->click('[data-testid="change-test-submit"]');
}

it('tests a PR and shows it would be held, with pins on the map', function () {
    $repository = setUpChangeTester([4790 => [['filename' => 'app/Http/Kernel.php'], ['filename' => 'app/Support/Slug.php']]]);

    $page = visit(route('repos.risk-profile.show', $repository));
    testChange($page, '#4790')
        ->assertSee('Would be held: API middleware and HTTP kernel')
        ->assertSee('Asks @mhansen.')
        ->assertSee('The co-owner gate is off in this repository')
        ->assertSee('app/Http/Kernel.php')
        ->assertVisible('[data-testid="risk-map-pin"][data-path="app/Http/Kernel.php"]')
        ->assertSee('PR #4790')
        ->assertNoJavascriptErrors();

    expect($page->script('document.querySelectorAll(\'[data-testid="risk-map-pin"]\').length'))->toBe(2);
});

it('marks the held area and the touched areas on the Areas tab', function () {
    $repository = setUpChangeTester([12 => [['filename' => 'app/Http/Kernel.php'], ['filename' => 'app/Slug/Slug.php']]]);

    $page = visit(route('repos.risk-profile.show', $repository));
    testChange($page, '12')->assertSee('Would be held');
    $page->click('[data-testid="risk-tab-areas"]')
        ->assertSee('Holds #12')
        ->assertSee('In #12')
        ->assertNoJavascriptErrors();

    expect($page->script('document.querySelectorAll(\'[data-testid="risk-area-card"].outline\').length'))->toBe(1);
});

it('says a change in no area opens as a normal PR', function () {
    $repository = setUpChangeTester([4755 => [['filename' => 'app/Support/Slug.php']]]);

    $page = visit(route('repos.risk-profile.show', $repository));
    testChange($page, '4755')
        ->assertSee('Would open as a normal PR: no changed file matches any area')
        ->assertSee('If these files are risky, add an area for them in .yak/risk-profile.yml.')
        ->assertDontSee('Would be held:')
        ->assertNoJavascriptErrors();
});

it('reports a PR that is not found', function () {
    $repository = setUpChangeTester();

    testChange(visit(route('repos.risk-profile.show', $repository)), '#9999')
        ->assertSee('PR #9999 was not found in Geocodio/geocodio.')
        ->assertNoJavascriptErrors();
});

it('reports a GitHub error and offers Try again', function () {
    $repository = setUpChangeTester([77 => 500]);

    $page = visit(route('repos.risk-profile.show', $repository));
    testChange($page, '77')
        ->assertSee('Could not read the change from GitHub. Try again.')
        ->assertVisible('[data-testid="change-test-retry"]');
    $page->click('[data-testid="change-test-retry"]')
        ->assertSee('Could not read the change from GitHub. Try again.')
        ->assertNoJavascriptErrors();
});

it('validates empty and unsafe input in the browser', function (string $input) {
    $repository = setUpChangeTester();

    testChange(visit(route('repos.risk-profile.show', $repository)), $input)
        ->assertSee('Enter a PR number or a branch name to test.')
        ->assertSee('The result appears here.')
        ->assertNoJavascriptErrors();
})->with(['empty' => [''], 'spaces' => ['   '], 'traversal' => ['a..b'], 'zero' => ['0'], 'shell' => ['x;rm']]);

it('shows the loading state while the request is in flight', function () {
    $repository = setUpChangeTester([4790 => [['filename' => 'app/Http/Kernel.php']]]);

    $page = visit(route('repos.risk-profile.show', $repository));
    $page->script(<<<'JS'
        (() => {
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (...args) { setTimeout(() => send.apply(this, args), 1500); };
            const fetchOriginal = window.fetch;
            window.fetch = (...args) => new Promise((resolve) => setTimeout(() => resolve(fetchOriginal(...args)), 1500));
        })()
    JS);
    testChange($page, '#4790')
        ->assertSee('Testing…')
        ->assertSee('Fetching the files in #4790 from GitHub…')
        ->assertSee('Then matching each path against the areas on')
        ->assertAttribute('[data-testid="change-test-submit"]', 'disabled', '')
        ->assertSee('Would be held')
        ->assertNoJavascriptErrors();
});

it('explains a floor hold that has no held area', function () {
    $repository = setUpChangeTester([6 => [['filename' => '.yak/config.yml']]]);

    testChange(visit(route('repos.risk-profile.show', $repository)), '6')
        ->assertSee("This change touches Yak's own config files, so Yak always holds it.")
        ->assertSee('Always held')
        ->assertNoJavascriptErrors();
});

it('shows the cap message even when a held area exists, and caps the file list at 200', function () {
    $files = array_map(fn (int $number): array => ['filename' => "src/File{$number}.php"], range(1, 299));
    $files[] = ['filename' => 'app/Http/Kernel.php'];
    $repository = setUpChangeTester(branches: ['big-change' => $files]);

    testChange(visit(route('repos.risk-profile.show', $repository)), 'big-change')
        ->assertSee('Would be held: API middleware and HTTP kernel')
        ->assertSee('GitHub compares at most 300 files, so Yak cannot see every file and would hold this change.')
        ->assertSee('Showing 200 of the changed files.')
        ->assertVisible('[data-testid="risk-map-pin"]')
        ->assertNoJavascriptErrors();
});

it('restores the result from the query string and clears it', function () {
    $repository = setUpChangeTester([4790 => [['filename' => 'app/Http/Kernel.php']]], gateMode: 'enforce');

    $page = visit(route('repos.risk-profile.show', $repository) . '?change=4790');
    $page->assertSee('Would be held: API middleware and HTTP kernel')
        ->assertDontSee('The co-owner gate is off')
        ->assertValue('[data-testid="change-test-input"]', '4790')
        ->click('[data-testid="change-test-clear"]')
        ->assertSee('The result appears here.')
        ->assertDontSee('Would be held:')
        ->assertNoJavascriptErrors();
});
