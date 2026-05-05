<?php

use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\WatchdogStuckDeploymentsJob;
use App\Models\BranchDeployment;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    config()->set('yak.deployments.stuck_starting_minutes', 30);
    config()->set('yak.deployments.stuck_destroying_minutes', 60);
});

it('destroys deployments stuck in starting past the threshold', function () {
    $stuck = BranchDeployment::factory()->starting()->create();
    BranchDeployment::query()->where('id', $stuck->id)->update(['updated_at' => now()->subMinutes(45)]);

    $fresh = BranchDeployment::factory()->starting()->create();
    BranchDeployment::query()->where('id', $fresh->id)->update(['updated_at' => now()->subMinutes(10)]);

    (new WatchdogStuckDeploymentsJob)->handle();

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $stuck->id);
    Bus::assertNotDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $fresh->id);
});

it('redestroys deployments stuck in destroying past the threshold', function () {
    $stuck = BranchDeployment::factory()->hibernated()->create();
    BranchDeployment::query()->where('id', $stuck->id)->update([
        'status' => 'destroying',
        'updated_at' => now()->subHours(2),
    ]);

    (new WatchdogStuckDeploymentsJob)->handle();

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $stuck->id);
});

it('ignores running and hibernated deployments', function () {
    $running = BranchDeployment::factory()->running()->create();
    BranchDeployment::query()->where('id', $running->id)->update(['updated_at' => now()->subDays(1)]);

    $hibernated = BranchDeployment::factory()->hibernated()->create();
    BranchDeployment::query()->where('id', $hibernated->id)->update(['updated_at' => now()->subDays(1)]);

    (new WatchdogStuckDeploymentsJob)->handle();

    Bus::assertNotDispatched(DestroyDeploymentJob::class);
});
