<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->tmp = sys_get_temp_dir() . '/yak-skills-controller-' . uniqid();
    File::makeDirectory($this->tmp, recursive: true);
    File::makeDirectory($this->tmp . '/bundled', recursive: true);
    config()->set('yak.plugins_dir', $this->tmp);
    config()->set('yak.skills_dir', $this->tmp . '/bundled');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * Writes a `known_marketplaces.json` + marketplace manifest fixture with the
 * given plugin entries (each: name, description, category).
 *
 * @param  array<int, array{name: string, description?: string, category?: ?string}>  $plugins
 */
function writeMarketplaceFixture(string $tmp, string $marketplace, array $plugins): void
{
    File::makeDirectory("{$tmp}/marketplaces/{$marketplace}/.claude-plugin", recursive: true);

    File::put("{$tmp}/known_marketplaces.json", json_encode([
        $marketplace => [
            'source' => ['repo' => "github:acme/{$marketplace}"],
            'installLocation' => "{$tmp}/marketplaces/{$marketplace}",
            'lastUpdated' => '2026-04-14T00:00:00Z',
        ],
    ]));

    File::put(
        "{$tmp}/marketplaces/{$marketplace}/.claude-plugin/marketplace.json",
        json_encode([
            'owner' => ['name' => 'acme'],
            'plugins' => array_map(fn (array $p) => [
                'name' => $p['name'],
                'description' => $p['description'] ?? "{$p['name']} description",
                'category' => array_key_exists('category', $p) ? $p['category'] : 'general',
            ], $plugins),
        ]),
    );
}

/**
 * @param  array<int, array{scope?: string, version?: string}>  $overrides  keyed by plugin key (name@marketplace)
 */
function writeInstalledPluginsFixture(string $tmp, array $keys): void
{
    $plugins = [];
    foreach ($keys as $key) {
        $plugins[$key] = [[
            'scope' => 'user', 'installPath' => '/x', 'version' => '1', 'installedAt' => '2026-01-01T00:00:00Z',
        ]];
    }

    File::put("{$tmp}/installed_plugins.json", json_encode(['version' => 2, 'plugins' => $plugins]));
}

it('renders the page', function () {
    $this->get(route('skills'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Skills/Index')
            ->has('installed')
            ->has('bundled')
            ->has('available')
            ->has('available.items')
            ->has('available.page')
            ->has('available.lastPage')
            ->has('available.total')
            ->has('available.perPage')
            ->has('categories')
            ->has('recommended')
            ->has('marketplaces')
            ->where('filters.search', '')
            ->where('filters.filter', 'all')
            ->where('filters.category', ''));
});

it('installs a plugin from a marketplace', function () {
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    $this->post(route('skills.install'), [
        'name' => 'code-review',
        'marketplace' => 'claude-plugins-official',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Installed code-review.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'code-review@claude-plugins-official'));
});

it('installs a plugin from a url', function () {
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    $this->post(route('skills.install'), [
        'url' => 'https://github.com/owner/plugin.git',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Plugin installed.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'https://github.com/owner/plugin.git'));
});

it('validates the install request requires a url or a name', function () {
    $this->post(route('skills.install'), [])
        ->assertSessionHasErrors(['url', 'name']);
});

it('surfaces a claude cli failure verbatim as an error flash', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

    $this->post(route('skills.install'), [
        'name' => 'code-review',
        'marketplace' => 'claude-plugins-official',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'claude plugins install \'code-review@claude-plugins-official\' failed: boom');
});

it('filters installed plugins by search', function () {
    File::put($this->tmp . '/installed_plugins.json', json_encode([
        'version' => 2,
        'plugins' => [
            'code-review@official' => [[
                'scope' => 'user', 'installPath' => '/x', 'version' => '1', 'installedAt' => '2026-01-01T00:00:00Z',
            ]],
            'frontend-design@official' => [[
                'scope' => 'user', 'installPath' => '/x', 'version' => '1', 'installedAt' => '2026-01-01T00:00:00Z',
            ]],
        ],
    ]));

    $this->get(route('skills', ['search' => 'frontend']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('installed', 1, fn (Assert $row) => $row
                ->where('name', 'frontend-design')
                ->etc()));
});

it('toggles a plugin enabled/disabled', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $this->patch(route('skills.update', 'code-review@official'), ['enabled' => false])
        ->assertRedirect()
        ->assertSessionHas('success', 'Disabled code-review@official.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins disable')
        && str_contains($p->command, 'code-review@official'));
});

it('uninstalls a plugin', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $this->delete(route('skills.destroy', 'code-review@official'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Uninstalled code-review@official.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins uninstall')
        && str_contains($p->command, 'code-review@official'));
});

it('updates a plugin and reports the new version', function () {
    Process::fake(['*' => Process::result(
        output: "Checking for updates for plugin \"code-review@official\" at user scope…\n✔ Updated code-review to version (2.1.0).\n",
        exitCode: 0,
    )]);

    $this->post(route('skills.upgrade', 'code-review@official'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Updated code-review@official (2.1.0).');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins update')
        && str_contains($p->command, 'code-review@official')
        && str_contains($p->command, '--yes'));
});

it('says so when a plugin is already at the latest version', function () {
    Process::fake(['*' => Process::result(
        output: "Checking for updates for plugin \"code-review@official\" at user scope…\n✔ code-review is already at the latest version (0120fb83da5d).\n",
        exitCode: 0,
    )]);

    $this->post(route('skills.upgrade', 'code-review@official'))
        ->assertRedirect()
        ->assertSessionHas('success', 'code-review@official is already at the latest version (0120fb83da5d).');
});

it('paginates available plugins', function () {
    writeMarketplaceFixture($this->tmp, 'acme', array_map(
        fn (int $i) => ['name' => sprintf('plugin-%02d', $i)],
        range(1, 30),
    ));

    $this->get(route('skills'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('available.items', 24)
            ->where('available.page', 1)
            ->where('available.lastPage', 2)
            ->where('available.total', 30)
            ->where('available.perPage', 24));

    $this->get(route('skills', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('available.items', 6)
            ->where('available.page', 2));

    $this->get(route('skills', ['page' => 99]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('available.page', 2)
            ->has('available.items', 6));
});

it('sorts available plugins by category then name and computes category counts', function () {
    writeMarketplaceFixture($this->tmp, 'acme', [
        ['name' => 'alpha-charlie', 'category' => 'alpha'],
        ['name' => 'alpha-alpha', 'category' => 'alpha'],
        ['name' => 'alpha-bravo', 'category' => 'alpha'],
        ['name' => 'zeta-bravo', 'category' => 'zeta'],
        ['name' => 'zeta-alpha', 'category' => 'zeta'],
        ['name' => 'null-bravo', 'category' => null],
        ['name' => 'null-alpha', 'category' => null],
    ]);

    $this->get(route('skills'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('available.items', 7, fn (Assert $row) => $row->where('name', 'alpha-alpha')->etc())
            ->where('available.items.1.name', 'alpha-bravo')
            ->where('available.items.2.name', 'alpha-charlie')
            ->where('available.items.3.name', 'zeta-alpha')
            ->where('available.items.4.name', 'zeta-bravo')
            ->where('available.items.5.name', 'null-alpha')
            ->where('available.items.6.name', 'null-bravo')
            ->where('categories', [
                ['value' => 'alpha', 'label' => 'Alpha', 'count' => 3],
                ['value' => 'zeta', 'label' => 'Zeta', 'count' => 2],
                ['value' => 'other', 'label' => 'Other', 'count' => 2],
            ]));
});

it('filters available plugins by category, including other for null category', function () {
    writeMarketplaceFixture($this->tmp, 'acme', [
        ['name' => 'alpha-charlie', 'category' => 'alpha'],
        ['name' => 'alpha-alpha', 'category' => 'alpha'],
        ['name' => 'alpha-bravo', 'category' => 'alpha'],
        ['name' => 'zeta-bravo', 'category' => 'zeta'],
        ['name' => 'zeta-alpha', 'category' => 'zeta'],
        ['name' => 'null-bravo', 'category' => null],
        ['name' => 'null-alpha', 'category' => null],
    ]);

    $this->get(route('skills', ['category' => 'alpha']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('available.items', 3)
            ->where('available.total', 3)
            ->where('available.items.0.name', 'alpha-alpha')
            ->where('categories', [
                ['value' => 'alpha', 'label' => 'Alpha', 'count' => 3],
                ['value' => 'zeta', 'label' => 'Zeta', 'count' => 2],
                ['value' => 'other', 'label' => 'Other', 'count' => 2],
            ]));

    $this->get(route('skills', ['category' => 'other']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('available.items', 2)
            ->where('available.total', 2)
            ->where('available.items.0.name', 'null-alpha')
            ->where('available.items.1.name', 'null-bravo'));
});

it('recommends configured popular plugins first, then plugins sharing an installed category', function () {
    writeMarketplaceFixture($this->tmp, 'acme', [
        ['name' => 'my-installed', 'category' => 'productivity'],
        ['name' => 'code-review', 'category' => null],
        ['name' => 'context7', 'category' => null],
        ['name' => 'sibling-c', 'category' => 'productivity'],
        ['name' => 'sibling-a', 'category' => 'productivity'],
        ['name' => 'sibling-b', 'category' => 'productivity'],
        ['name' => 'unrelated', 'category' => 'testing'],
    ]);

    writeInstalledPluginsFixture($this->tmp, ['my-installed@acme']);

    $this->get(route('skills'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recommended', 5)
            ->where('recommended.0.name', 'code-review')
            ->where('recommended.0.recommendedReason', 'popular')
            ->where('recommended.1.name', 'context7')
            ->where('recommended.1.recommendedReason', 'popular')
            ->where('recommended.2.name', 'sibling-a')
            ->where('recommended.2.recommendedReason', 'similar')
            ->where('recommended.3.name', 'sibling-b')
            ->where('recommended.3.recommendedReason', 'similar')
            ->where('recommended.4.name', 'sibling-c')
            ->where('recommended.4.recommendedReason', 'similar'));

    $this->get(route('skills', ['search' => 'code']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('recommended', []));
});

it('echoes the selected category back in filters', function () {
    $this->get(route('skills', ['category' => 'testing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.category', 'testing'));
});

it('dedupes recommendations by name across marketplaces and skips installed names', function () {
    foreach (['official', 'mirror'] as $marketplace) {
        File::makeDirectory("{$this->tmp}/marketplaces/{$marketplace}/.claude-plugin", recursive: true);
        File::put("{$this->tmp}/marketplaces/{$marketplace}/.claude-plugin/marketplace.json", json_encode([
            'owner' => ['name' => 'acme'],
            'plugins' => [
                ['name' => 'code-review', 'description' => "Review from {$marketplace}", 'category' => 'development'],
                ['name' => 'security-guidance', 'description' => "Security from {$marketplace}", 'category' => 'security'],
            ],
        ]));
    }

    File::put("{$this->tmp}/known_marketplaces.json", json_encode([
        'official' => ['source' => ['repo' => 'github:acme/official'], 'installLocation' => "{$this->tmp}/marketplaces/official"],
        'mirror' => ['source' => ['repo' => 'github:acme/mirror'], 'installLocation' => "{$this->tmp}/marketplaces/mirror"],
    ]));
    writeInstalledPluginsFixture($this->tmp, ['security-guidance@official']);

    $this->get(route('skills'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recommended', 1, fn (Assert $row) => $row
                ->where('name', 'code-review')
                ->where('marketplace', 'official')
                ->etc()));
});
