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

it('draws covered and uncovered files as different kinds of block', function () {
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
        ->assertSee('GitHub returned only part of the file list for aaaaaaa, so Yak cannot place every file in an area. The Areas tab and the test helper still work.')
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

it('draws files under .yak with a dashed always-held outline and a legend entry', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());
    $this->actingAs(User::factory()->create());

    $page = visit(route('repos.risk-profile.show', $repository))
        ->assertVisible('[data-testid="risk-map-legend-floor"]')
        ->assertSee('Always held');

    expect($page->script('document.querySelectorAll(\'[data-testid="risk-map-segment"][data-floor="true"] [data-testid="risk-map-floor-outline"]\').length'))->toBeGreaterThan(0)
        ->and($page->script('getComputedStyle(document.querySelector(\'[data-testid="risk-map-floor-outline"]\')).borderTopStyle'))->toBe('dashed');
});

it('moves between blocks with the keyboard and opens the panel on Enter', function () {
    $repository = Repository::factory()->create();
    fakeYakFiles(['risk-profile.yml' => riskMapProfileYaml()]);
    fakeRiskMapGitHub(riskMapPaths());
    $this->actingAs(User::factory()->create());

    $page = visit(route('repos.risk-profile.show', $repository))
        ->assertVisible('[data-testid="risk-map-canvas"]');

    expect($page->script('document.querySelectorAll(\'button[data-testid="risk-map-segment"][tabindex="0"]\').length'))->toBe(1);

    $page->script('document.querySelector(\'button[data-testid="risk-map-segment"][tabindex="0"]\').focus()');
    $before = $page->script('document.activeElement.getAttribute("aria-label")');
    $page->keys('[data-testid="risk-map-canvas"]', ['End']);
    $after = $page->script('document.activeElement.getAttribute("aria-label")');
    $page->keys('[data-testid="risk-map-canvas"]', ['Enter']);

    expect($after)->not->toBe($before)
        ->and($page->script('document.activeElement.getAttribute("aria-pressed")'))->toBe('true')
        ->and($page->script('document.querySelector(\'[data-testid="risk-map-panel"]\').textContent.includes(' . json_encode(str_contains($after, 'Support') ? 'Small shared helpers.' : 'Every request passes through these.') . ')'))->toBeTrue();
});

it('marks files whose area could not be checked as held, not as covered', function () {
    $repository = Repository::factory()->create();
    $yaml = <<<'YAML'
version: 1
areas:
  - name: Hostile glob
    risk: high
    paths: ["*a*a*a*a*a*a*a*a*a*a*a*a*b"]
    symbols: []
    rationale: Cannot be evaluated.
    evidence: ["Synthetic"]
unknowns: []
YAML;
    fakeYakFiles(['risk-profile.yml' => $yaml]);
    fakeRiskMapGitHub([str_repeat('a', 60) . 'bc', 'README.md']);
    $this->actingAs(User::factory()->create());
    $jit = ini_get('pcre.jit');
    ini_set('pcre.jit', '0');

    try {
        $page = visit(route('repos.risk-profile.show', $repository))
            ->assertVisible('[data-testid="risk-map-canvas"]');
        $kinds = $page->script('[...document.querySelectorAll(\'[data-testid="risk-map-segment"]\')].map((el) => el.dataset.kind).join(",")');
        $legend = $page->script('!!document.querySelector(\'[data-testid="risk-map-legend-unchecked"]\')');
    } finally {
        ini_set('pcre.jit', $jit);
    }

    expect($kinds)->toContain('unchecked')->and($legend)->toBeTrue();
});
