<?php

use App\Models\Repository;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('edit exposes the existing manifest values', function () {
    $repo = Repository::factory()->create([
        'preview_manifest' => [
            'port' => 3000,
            'health_probe_path' => '/healthz',
            'cold_start' => 'pnpm dev',
            'checkout_refresh' => 'pnpm install',
        ],
    ]);

    $this->get(route('repos.edit', $repo))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manifest.port', 3000)
            ->where('manifest.healthProbePath', '/healthz'));
});

test('manifest update persists changes', function () {
    $repo = Repository::factory()->create(['preview_manifest' => null]);

    $this->put(route('repos.manifest.update', $repo), [
        'port' => 8080,
        'health_probe_path' => '/',
        'cold_start' => 'docker compose up -d',
        'checkout_refresh' => '',
        'wake_timeout_seconds' => 120,
    ])->assertRedirect();

    expect($repo->fresh()->preview_manifest['port'])->toBe(8080);
    expect($repo->fresh()->preview_manifest['cold_start'])->toBe('docker compose up -d');
});

test('manifest update validates port is an integer in [1, 65535]', function () {
    $repo = Repository::factory()->create();

    $this->put(route('repos.manifest.update', $repo), [
        'port' => 0,
        'health_probe_path' => '/',
        'wake_timeout_seconds' => 120,
    ])->assertSessionHasErrors(['port']);
});
