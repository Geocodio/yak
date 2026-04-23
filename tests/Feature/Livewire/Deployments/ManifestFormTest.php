<?php

use App\Livewire\Deployments\ManifestForm;
use App\Models\Repository;
use App\Models\User;
use Livewire\Livewire;

it('loads existing manifest values into the form', function () {
    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'port' => 3000,
            'health_probe_path' => '/healthz',
            'cold_start' => 'pnpm dev',
            'checkout_refresh' => 'pnpm install',
        ],
    ]);

    Livewire::actingAs(User::factory()->create())
        ->test(ManifestForm::class, ['repository' => $repo])
        ->assertSet('port', 3000)
        ->assertSet('healthProbePath', '/healthz');
});

it('persists changes on save', function () {
    $repo = Repository::factory()->create(['preview_manifest' => null]);

    Livewire::actingAs(User::factory()->create())
        ->test(ManifestForm::class, ['repository' => $repo])
        ->set('port', 8080)
        ->set('coldStart', 'docker compose up -d')
        ->call('save');

    expect($repo->fresh()->preview_manifest['port'])->toBe(8080);
    expect($repo->fresh()->preview_manifest['cold_start'])->toBe('docker compose up -d');
});

it('validates port is an integer in [1, 65535]', function () {
    $repo = Repository::factory()->create();
    Livewire::actingAs(User::factory()->create())
        ->test(ManifestForm::class, ['repository' => $repo])
        ->set('port', 0)
        ->call('save')
        ->assertHasErrors(['port']);
});
