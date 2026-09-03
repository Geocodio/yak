<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->tmp = sys_get_temp_dir() . '/yak-marketplaces-controller-' . uniqid();
    File::makeDirectory($this->tmp, recursive: true);
    File::makeDirectory($this->tmp . '/bundled', recursive: true);
    config()->set('yak.plugins_dir', $this->tmp);
    config()->set('yak.skills_dir', $this->tmp . '/bundled');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('adds a marketplace via the form', function () {
    Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

    $this->post(route('marketplaces.store'), ['source' => 'github:acme/plugins'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Marketplace added.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins marketplace add')
        && str_contains($p->command, 'github:acme/plugins'));
});

it('validates the new marketplace source', function () {
    $this->post(route('marketplaces.store'), ['source' => 'ab'])
        ->assertSessionHasErrors(['source']);
});

it('removes a marketplace', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $this->delete(route('marketplaces.destroy', 'acme-plugins'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Removed marketplace acme-plugins.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins marketplace remove')
        && str_contains($p->command, 'acme-plugins'));
});

it('refreshes marketplaces', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $this->post(route('marketplaces.refresh'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Marketplaces refreshed.');

    Process::assertRan(fn ($p) => str_contains($p->command, 'plugins marketplace update'));
});

it('surfaces a claude cli failure verbatim as an error flash', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'network down', exitCode: 1)]);

    $this->post(route('marketplaces.store'), ['source' => 'github:acme/plugins'])
        ->assertRedirect()
        ->assertSessionHas('error', 'claude plugins marketplace add \'github:acme/plugins\' failed: network down');
});
