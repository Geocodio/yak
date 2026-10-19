<?php

use App\Models\Repository;
use App\Models\User;

function areasTabProfileYaml(): string
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
  - name: Slug helpers
    risk: low
    paths: ["app/Slug/**"]
    symbols: []
    rationale: Small helpers.
    evidence: ["Helpers only"]
unknowns: ["Does launcher/ touch production credentials?"]
YAML;
}

function visitAreasTab(Repository $repository)
{
    return visit(route('repos.risk-profile.show', $repository) . '#areas');
}

it('lists areas riskiest first with owners and a dead-area warning', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => areasTabProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php', 'app/Support/Slug.php', 'app/Slug/Slug.php'], "app/Http/** @mhansen\n");
    $this->actingAs(User::factory()->create());

    visitAreasTab($repository)
        ->assertSee('Areas, riskiest first')
        ->assertSee('API middleware and HTTP kernel')
        ->assertSee('@mhansen')
        ->assertSee('Matches no files')
        ->assertSee('Never matches')
        ->assertSee('Biggest gaps')
        ->assertSee('Coverage by directory')
        ->assertSee('Changes are held for a co-owner')
        ->assertSee('Changes open as normal PRs')
        ->assertSee('app/Support')
        ->assertNoJavascriptErrors();
});

it('lists open questions with the auto-approval badge', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => areasTabProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);
    $this->actingAs(User::factory()->create());

    visitAreasTab($repository)
        ->assertSeeIn('#open-questions', 'Open questions')
        ->assertSeeIn('#open-questions', '1 open')
        ->assertSeeIn('#open-questions', 'Does launcher/ touch production credentials?')
        ->assertSeeIn('#open-questions', 'Blocks auto-approval')
        ->assertNoJavascriptErrors();
});

it('says there are no gaps when every file is covered', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => areasTabProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);
    $this->actingAs(User::factory()->create());

    visitAreasTab($repository)
        ->assertSee('No gaps')
        ->assertSee('Every directory is covered by an area.')
        ->assertNoJavascriptErrors();
});

it('hides coverage panels but keeps the list when the map is unavailable', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => areasTabProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php'], null, true);
    $this->actingAs(User::factory()->create());

    visitAreasTab($repository)
        ->assertSee('Areas, riskiest first')
        ->assertSee('API middleware and HTTP kernel')
        ->assertSee('Open questions')
        ->assertDontSee('Coverage by directory')
        ->assertDontSee('Biggest gaps')
        ->assertNoJavascriptErrors();
});

it('never rounds coverage up to 100% while a gap remains', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => areasTabProfileYaml()]);
    fakeRiskMapGitHub([...array_map(fn ($i) => "app/Http/Middleware/M{$i}.php", range(1, 199)), 'README.md'], "app/Http/** @mhansen\n");
    $this->actingAs(User::factory()->create());

    visitAreasTab($repository)
        ->assertSeeIn('[data-testid="risk-coverage"]', '99% of 200 files sit in an area.')
        ->assertNoJavascriptErrors();
});

it('flags a dead low-risk area in its compact row and nests headings without skipping a level', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => areasTabProfileYaml()]);
    fakeRiskMapGitHub(['app/Http/Kernel.php']);
    $this->actingAs(User::factory()->create());

    $page = visitAreasTab($repository)
        ->assertSeeIn('[data-testid="risk-area-row"] [data-testid="risk-area-dead"]', 'Matches no files')
        ->assertNoJavascriptErrors();

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="risk-areas"] h2, [data-testid="risk-areas"] h3, [data-testid="risk-areas"] h4')].map((el) => el.tagName)
    JS))->toContain('H3')
        ->and($page->script(<<<'JS'
        document.querySelectorAll('[data-testid="risk-group-high"] > div h3').length
    JS))->toBe(1);
});
