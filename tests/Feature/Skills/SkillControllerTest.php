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

it('renders the page', function () {
    $this->get(route('skills'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Skills/Index')
            ->has('installed')
            ->has('bundled')
            ->has('available')
            ->has('marketplaces')
            ->where('filters.search', '')
            ->where('filters.filter', 'all'));
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

it('updates a plugin', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $this->post(route('skills.upgrade', 'code-review@official'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Updated code-review@official.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins update')
        && str_contains($p->command, 'code-review@official'));
});
