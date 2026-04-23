<?php

use App\Models\Repository;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;

it('uses sandbox_snapshot as the clone source when set', function () {
    $repository = Repository::factory()->create([
        'sandbox_snapshot' => 'yak-tpl-example-org-example-repo/ready-v3',
        'current_template_version' => 3,
    ]);

    $manager = app(IncusSandboxManager::class);
    expect($manager->resolveSource($repository))->toBe('yak-tpl-example-org-example-repo/ready-v3');
});

it('falls back to legacy {template}/ready when sandbox_snapshot is null and legacy snapshot exists', function () {
    Process::fake([
        'incus snapshot list *' => Process::result(exitCode: 0, output: 'ready'),
    ]);

    $repository = Repository::factory()->create([
        'slug' => 'promote',
        'sandbox_snapshot' => null,
        'current_template_version' => 0,
    ]);

    $manager = app(IncusSandboxManager::class);
    $source = $manager->resolveSource($repository);

    expect($source)->not->toBeEmpty();
    expect($source)->toContain('ready');
});

it('falls back to base template snapshot when no repo template exists', function () {
    Process::fake([
        'incus snapshot list yak-tpl-*' => Process::result(exitCode: 1),
        'incus snapshot list yak-base *' => Process::result(exitCode: 0, output: 'ready'),
    ]);

    $repository = Repository::factory()->create([
        'sandbox_snapshot' => null,
        'current_template_version' => 0,
    ]);

    $manager = app(IncusSandboxManager::class);
    $source = $manager->resolveSource($repository);

    expect($source)->toContain('yak-base');
});
