<?php

use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\SweepExpiredDeploymentsJob;
use App\Models\BranchDeployment;
use Illuminate\Support\Facades\Bus;

beforeEach(fn () => Bus::fake());

it('dispatches DestroyDeploymentJob for deployments past destroy_days', function () {
    config()->set('yak.deployments.destroy_days', 30);

    $stale = BranchDeployment::factory()->hibernated()->create([
        'last_accessed_at' => now()->subDays(31),
    ]);
    $recent = BranchDeployment::factory()->hibernated()->create([
        'last_accessed_at' => now()->subDays(5),
    ]);

    (new SweepExpiredDeploymentsJob)->handle();

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $stale->id);
    Bus::assertNotDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $recent->id);
});

it('skips already-destroyed deployments', function () {
    BranchDeployment::factory()->destroyed()->create(['last_accessed_at' => now()->subDays(100)]);

    (new SweepExpiredDeploymentsJob)->handle();

    Bus::assertNothingDispatched();
});
