<?php

use App\Models\Repository;
use App\Models\User;

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

it('draws the map with blocks that stay inside the map, do not overlap and are sized by file count', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());
    $this->actingAs(User::factory()->create());

    $page = visit(route('repos.risk-profile.show', $repository))
        ->assertVisible('[data-testid="risk-map-canvas"]')
        ->assertNoJavascriptErrors();

    $result = $page->script(<<<'JS'
        (() => {
            const map = document.querySelector('[data-testid="risk-map-canvas"]').getBoundingClientRect();
            const segments = [...document.querySelectorAll('[data-testid="risk-map-segment"]')];
            const leaves = segments.map((el) => el.getBoundingClientRect());
            const inside = leaves.every((r) => r.left >= map.left - 0.5 && r.right <= map.right + 0.5 && r.top >= map.top - 0.5 && r.bottom <= map.bottom + 0.5);
            const overlaps = leaves.some((a, i) => leaves.some((b, j) => i < j && Math.min(a.right, b.right) - Math.max(a.left, b.left) > 1 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 1));
            const ratios = segments.map((el, i) => ({ files: Number(el.dataset.files), area: leaves[i].width * leaves[i].height })).filter((s) => s.area > 400);
            const unit = ratios.map((s) => s.area / s.files);
            const proportional = unit.every((value) => value / unit[0] < 1.6 && value / unit[0] > 0.6);
            return { count: leaves.length, compared: unit.length, inside, overlaps, proportional };
        })()
    JS);

    expect($result['count'])->toBeGreaterThan(2)
        ->and($result['compared'])->toBeGreaterThan(2)
        ->and($result['inside'])->toBeTrue()
        ->and($result['overlaps'])->toBeFalse()
        ->and($result['proportional'])->toBeTrue();
});

it('opens an area in the side panel on click', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertVisible('[data-testid="risk-map-panel"]')
        ->assertSeeIn('[data-testid="risk-map-panel"]', 'Every request passes through these.')
        ->assertSeeIn('[data-testid="risk-map-panel"]', 'Held for a co-owner')
        ->assertSeeIn('[data-testid="risk-map-panel"]', 'app/Http/Kernel.php')
        ->click('[data-testid="risk-map-segment"][aria-label*="Support helpers"]')
        ->assertSeeIn('[data-testid="risk-map-panel"]', 'Small shared helpers.')
        ->assertSeeIn('[data-testid="risk-map-panel"]', 'Opens as a normal PR')
        ->assertNoJavascriptErrors();
});

it('marks files whose area could not be checked as their own kind of block', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());
    $this->actingAs(User::factory()->create());

    $page = visit(route('repos.risk-profile.show', $repository))
        ->assertVisible('[data-testid="risk-map-canvas"]');

    $kinds = $page->script('[...new Set([...document.querySelectorAll(\'[data-testid="risk-map-segment"]\')].map((el) => el.dataset.kind))].sort().join(",")');

    expect($kinds)->toContain('area')->toContain('none');
});

it('shows the notice and switches to the Areas tab when the map is unavailable', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths(), null, true);
    $this->actingAs(User::factory()->create());

    visit(route('repos.risk-profile.show', $repository))
        ->assertSee('The map is not available')
        ->assertMissing('[data-testid="risk-map-canvas"]')
        ->click('Open the Areas tab')
        ->assertVisible('[data-testid="risk-areas"]')
        ->assertNoJavascriptErrors();
});

it('renders without horizontal scroll on a phone', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());
    $this->actingAs(User::factory()->create());

    $phone = visit(route('repos.risk-profile.show', $repository))->on()->mobile()
        ->assertVisible('[data-testid="risk-map-canvas"]')
        ->assertNoJavascriptErrors();

    expect($phone->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
