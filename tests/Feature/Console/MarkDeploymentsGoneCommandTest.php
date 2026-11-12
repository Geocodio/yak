<?php

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

function fakeIncusListing(string $names = "deploy-a\ndeploy-b\n"): void
{
    Process::fake(['*incus list*' => Process::result($names)]);
}

function statusOf(BranchDeployment $deployment): DeploymentStatus
{
    return BranchDeployment::withoutGlobalScopes()->findOrFail($deployment->id)->status;
}

it('marks rows whose container is missing as destroyed and keeps present ones', function () {
    fakeIncusListing();

    $missing = collect(['running', 'hibernated', 'starting', 'failed', 'pending'])
        ->map(fn (string $state) => BranchDeployment::factory()->{$state}()->create());
    $present = BranchDeployment::factory()->running()->create(['container_name' => 'deploy-a']);

    $this->artisan('yak:mark-deployments-gone')->assertExitCode(Command::SUCCESS);

    $missing->each(fn (BranchDeployment $row) => expect(statusOf($row))->toBe(DeploymentStatus::Destroyed));
    expect(statusOf($present))->toBe(DeploymentStatus::Running);

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'incus list'), 1);
    Process::assertRanTimes(fn () => true, 1);
});

it('is idempotent', function () {
    fakeIncusListing();
    BranchDeployment::factory()->running()->count(2)->create();

    $this->artisan('yak:mark-deployments-gone')->expectsOutputToContain('Total: 2')->assertExitCode(Command::SUCCESS);
    $this->artisan('yak:mark-deployments-gone')->expectsOutputToContain('Total: 0')->assertExitCode(Command::SUCCESS);
});

it('changes nothing on a dry run and prints would-mark counts per prior state', function () {
    fakeIncusListing();
    $rows = BranchDeployment::factory()->running()->count(2)->create();

    $this->artisan('yak:mark-deployments-gone', ['--dry-run' => true])
        ->expectsOutputToContain('running')
        ->expectsOutputToContain('Total: 2')
        ->assertExitCode(Command::SUCCESS);

    $rows->each(fn (BranchDeployment $row) => expect(statusOf($row))->toBe(DeploymentStatus::Running));
});

it('skips destroyed, destroying and empty container name rows', function () {
    fakeIncusListing();
    $destroyed = BranchDeployment::factory()->destroyed()->create();
    $destroying = BranchDeployment::factory()->create(['status' => DeploymentStatus::Destroying]);
    $unnamed = BranchDeployment::factory()->running()->create(['container_name' => '']);

    $this->artisan('yak:mark-deployments-gone')->expectsOutputToContain('Total: 0')->assertExitCode(Command::SUCCESS);

    expect(statusOf($destroyed))->toBe(DeploymentStatus::Destroyed)
        ->and(statusOf($destroying))->toBe(DeploymentStatus::Destroying)
        ->and(statusOf($unnamed))->toBe(DeploymentStatus::Running);
});

it('fails without marking anything when incus list exits non-zero', function () {
    Process::fake(['*incus list*' => Process::result('', 'boom', 1)]);
    $row = BranchDeployment::factory()->running()->create();

    $this->artisan('yak:mark-deployments-gone')->assertExitCode(Command::FAILURE);

    expect(statusOf($row))->toBe(DeploymentStatus::Running);
});
