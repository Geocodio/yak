<?php

use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Bus::fake();
});

test('rebuild dispatches RebuildDeploymentJob', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $this->post(route('deployments.rebuild', $deployment))
        ->assertRedirect(route('deployments.show', $deployment))
        ->assertSessionHas('success', 'Rebuild queued.');

    Bus::assertDispatched(RebuildDeploymentJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

test('destroy dispatches DestroyDeploymentJob', function () {
    $deployment = BranchDeployment::factory()->running()->create();

    $this->delete(route('deployments.destroy', $deployment))
        ->assertRedirect(route('deployments.show', $deployment))
        ->assertSessionHas('success', 'Destroy queued.');

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

test('marks a deployment long-lived', function () {
    $deployment = BranchDeployment::factory()->running()->create(['long_lived' => false]);

    $this->patch(route('deployments.hibernation.update', $deployment), [
        'long_lived' => true,
    ])
        ->assertRedirect(route('deployments.show', $deployment))
        ->assertSessionHas('success', 'Marked as long-lived.');

    expect($deployment->fresh()->long_lived)->toBeTrue();
});

test('reverts a deployment to standard hibernation and clears the custom timeout', function () {
    $deployment = BranchDeployment::factory()->running()->longLived(720)->create();

    $this->patch(route('deployments.hibernation.update', $deployment), [
        'long_lived' => false,
    ])
        ->assertRedirect(route('deployments.show', $deployment))
        ->assertSessionHas('success', 'Reverted to standard hibernation.');

    $fresh = $deployment->fresh();
    expect($fresh->long_lived)->toBeFalse();
    expect($fresh->idle_timeout_minutes)->toBeNull();
});

test('saves a custom hibernation timeout from shorthand', function () {
    $deployment = BranchDeployment::factory()->running()->longLived()->create();

    $this->patch(route('deployments.hibernation.update', $deployment), [
        'long_lived' => true,
        'timeout' => '2w',
    ])
        ->assertRedirect(route('deployments.show', $deployment))
        ->assertSessionHas('success', 'Hibernation timeout updated.');

    expect($deployment->fresh()->idle_timeout_minutes)->toBe(20160);
});

test('rejects an invalid hibernation duration with the exact message', function () {
    $deployment = BranchDeployment::factory()->running()->longLived()->create();

    $this->patch(route('deployments.hibernation.update', $deployment), [
        'long_lived' => true,
        'timeout' => 'nonsense',
    ])
        ->assertSessionHasErrors(['timeout' => 'Enter a duration like 3d, 12h, or 2w.']);

    expect($deployment->fresh()->idle_timeout_minutes)->toBeNull();
});
