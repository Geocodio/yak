<?php

use App\Jobs\Deployments\GarbageCollectTemplateSnapshotsJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Services\DeploymentContainerManager;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;

/**
 * Regression cover for task 5511, which burned a full setup run and then
 * failed on `The process "incus stop task-5511" exceeded the timeout of 60
 * seconds`. Two bugs stacked: incus stop waits forever for a clean shutdown
 * (`--timeout` defaults to -1), and the Process facade caps every command at
 * 60s unless told otherwise.
 */
it('bounds the graceful window when stopping a sandbox for promotion', function () {
    Process::fake();

    $repository = Repository::factory()->create(['current_template_version' => 1]);

    app(IncusSandboxManager::class)->promoteToTemplate('task-5511', $repository);

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus stop task-5511 --timeout'));
    Process::assertNotRan(fn ($process) => $process->command === 'incus stop task-5511');
});

it('force-kills a sandbox whose graceful stop fails', function () {
    Process::fake([
        'incus stop task-5511 --timeout*' => Process::result(errorOutput: 'shutdown timed out', exitCode: 1),
        '*' => Process::result(),
    ]);

    $repository = Repository::factory()->create(['current_template_version' => 1]);

    app(IncusSandboxManager::class)->promoteToTemplate('task-5511', $repository);

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus stop task-5511 --force'));
    // The promotion still completes rather than aborting the setup run.
    Process::assertRan(fn ($process) => str_contains($process->command, 'incus snapshot create'));
});

it('gives every incus command in a promotion more than the 60s default', function () {
    Process::fake();

    $repository = Repository::factory()->create(['current_template_version' => 1]);

    app(IncusSandboxManager::class)->promoteToTemplate('task-5511', $repository);

    Process::assertRan(function ($process) {
        expect($process->timeout)
            ->not->toBe(60, "`{$process->command}` is still on the Process facade's 60s default");

        return true;
    });
});

it('bounds the graceful window when hibernating a preview container', function () {
    Process::fake();

    $deployment = BranchDeployment::factory()->create(['container_name' => 'deploy-42']);

    app(DeploymentContainerManager::class)->stop($deployment);

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus stop deploy-42 --timeout'));
    Process::assertNotRan(fn ($process) => $process->command === 'incus stop deploy-42');
});

it('force-kills a preview container whose graceful stop fails', function () {
    Process::fake([
        'incus stop deploy-42 --timeout*' => Process::result(errorOutput: 'shutdown timed out', exitCode: 1),
        '*' => Process::result(),
    ]);

    $deployment = BranchDeployment::factory()->create(['container_name' => 'deploy-42']);

    app(DeploymentContainerManager::class)->stop($deployment);

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus stop deploy-42 --force'));
});

it('raises the failure when even a forced stop will not take', function () {
    Process::fake([
        'incus stop deploy-42*' => Process::result(errorOutput: 'container is wedged', exitCode: 1),
        '*' => Process::result(),
    ]);

    $deployment = BranchDeployment::factory()->create(['container_name' => 'deploy-42']);

    expect(fn () => app(DeploymentContainerManager::class)->stop($deployment))
        ->toThrow(RuntimeException::class, 'container is wedged');
});

it('gives snapshot garbage collection room to unwind ZFS datasets', function () {
    Process::fake([
        'incus snapshot list --format plain' => Process::result("yak-tpl-orphan/ready-v1\n"),
        '*' => Process::result(),
    ]);

    (new GarbageCollectTemplateSnapshotsJob)->handle();

    Process::assertRan(fn ($process) => str_contains($process->command, 'incus snapshot delete yak-tpl-orphan/ready-v1')
        && $process->timeout > 60);
});
