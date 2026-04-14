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

    File::put(
        $this->tmp . '/marketplaces/acme-plugins/.claude-plugin/marketplace.json',
        json_encode([
            'owner' => ['name' => 'acme'],
            'plugins' => [[
                'name' => 'demo-plugin',
                'description' => 'Demonstration plugin for browser test',
                'category' => 'demo',
            ]],
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

    $page->click('[data-test="install-demo-plugin@acme-plugins"]');

    $page->waitForText('Installed demo-plugin');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'demo-plugin@acme-plugins'));
});
