<?php

use App\Livewire\Settings\Skills;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->tmp = sys_get_temp_dir() . '/yak-skills-livewire-' . uniqid();
    File::makeDirectory($this->tmp, recursive: true);
    File::makeDirectory($this->tmp . '/bundled', recursive: true);
    config()->set('yak.plugins_dir', $this->tmp);
    config()->set('yak.skills_dir', $this->tmp . '/bundled');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('renders the page', function () {
    Livewire::test(Skills::class)
        ->assertOk()
        ->assertSee('Install and manage Claude Code plugins');
});

it('dispatches install via the component', function () {
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    Livewire::test(Skills::class)
        ->call('install', 'code-review', 'claude-plugins-official');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins install')
        && str_contains($p->command, 'code-review@claude-plugins-official'));
});

it('validates the install URL', function () {
    Livewire::test(Skills::class)
        ->set('installUrl', '')
        ->call('installFromUrl')
        ->assertHasErrors(['installUrl']);
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

    Livewire::test(Skills::class)
        ->set('search', 'frontend')
        ->assertSee('frontend-design')
        ->assertDontSee('code-review');
});

it('toggles a plugin enabled/disabled', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    Livewire::test(Skills::class)
        ->call('toggle', 'code-review@official', false);

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins disable')
        && str_contains($p->command, 'code-review@official'));
});

it('adds a marketplace via the form', function () {
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    Livewire::test(Skills::class)
        ->set('newMarketplace', 'github:acme/plugins')
        ->call('addMarketplace')
        ->assertHasNoErrors();

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins marketplace add')
        && str_contains($p->command, 'github:acme/plugins'));
});
