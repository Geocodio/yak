<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->tmp = sys_get_temp_dir() . '/yak-skills-browser-' . uniqid();
    File::makeDirectory($this->tmp . '/marketplaces/acme-plugins/.claude-plugin', recursive: true);
    File::makeDirectory($this->tmp . '/bundled', recursive: true);

    File::put($this->tmp . '/known_marketplaces.json', json_encode([
        'acme-plugins' => [
            'source' => ['repo' => 'github:acme/plugins'],
            'installLocation' => $this->tmp . '/marketplaces/acme-plugins',
            'lastUpdated' => '2026-04-14T00:00:00Z',
        ],
    ]));

    // 30 plugins across two categories: 15 "productivity" (including the
    // original demo-plugin, kept so the install-button test below still
    // targets a known key) and 15 "testing" -- enough to span two pages of
    // the 24-per-page Available list.
    $plugins = [[
        'name' => 'demo-plugin',
        'description' => 'Demonstration plugin for browser test',
        'category' => 'productivity',
    ]];

    for ($i = 2; $i <= 15; $i++) {
        $plugins[] = [
            'name' => sprintf('productivity-plugin-%02d', $i),
            'description' => 'A productivity plugin',
            'category' => 'productivity',
        ];
    }

    for ($i = 1; $i <= 15; $i++) {
        $plugins[] = [
            'name' => sprintf('testing-plugin-%02d', $i),
            'description' => 'A testing plugin',
            'category' => 'testing',
        ];
    }

    File::put(
        $this->tmp . '/marketplaces/acme-plugins/.claude-plugin/marketplace.json',
        json_encode([
            'owner' => ['name' => 'acme'],
            'plugins' => $plugins,
        ]),
    );

    config()->set('yak.plugins_dir', $this->tmp);
    config()->set('yak.skills_dir', $this->tmp . '/bundled');

    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('Skills page renders and the Install button click invokes the backend', function () {
    $page = visit(route('skills'));

    $page->assertNoJavaScriptErrors();
    $page->assertSee('Skills');
    $page->assertSee('demo-plugin');

    $page->click('[data-testid="install-demo-plugin@acme-plugins"]');

    $page->waitForText('Installed demo-plugin');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'demo-plugin@acme-plugins'));
});

test('Skills page filters by category and paginates the Available list', function () {
    $page = visit(route('skills'));

    $page->assertNoJavaScriptErrors();

    $page->assertVisible('[data-testid="category-all"]');
    $page->assertVisible('[data-testid="category-productivity"]');
    $page->assertVisible('[data-testid="category-testing"]');

    $page->click('[data-testid="category-testing"]');

    $page->assertSee('testing-plugin-01');
    $page->assertDontSee('demo-plugin');
    $page->assertDontSee('productivity-plugin-02');

    $page->click('[data-testid="category-all"]');

    $page->assertSee('demo-plugin');

    $page->click('[data-testid="available-next"]');

    $page->assertSee('Page 2 of 2');

    $page->assertNoJavaScriptErrors();
});
