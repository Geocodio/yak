<?php

use App\Models\CiDefaultBranchGreen;
use App\Models\CiFailureClaim;
use App\Models\CiJobFailure;
use App\Models\YakTask;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects a second failure row with the same job_id', function () {
    CiJobFailure::factory()->create(['job_id' => 42]);

    CiJobFailure::factory()->create(['job_id' => 42]);
})->throws(UniqueConstraintViolationException::class);

it('prunes failures older than the observation retention', function () {
    config(['yak.ci_scan.observation_retention_days' => 30]);

    $old = CiJobFailure::factory()->create(['failed_at' => now()->subDays(31)]);
    $recent = CiJobFailure::factory()->create(['failed_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => CiJobFailure::class])->assertSuccessful();

    expect(CiJobFailure::find($old->id))->toBeNull()
        ->and(CiJobFailure::find($recent->id))->not->toBeNull();
});

it('classifies test jobs with the ci_scan patterns', function () {
    expect(CiJobFailure::factory()->make(['job_name' => 'Test (Main)'])->isTestJob())->toBeTrue()
        ->and(CiJobFailure::factory()->make(['job_name' => 'Security Audit'])->isTestJob())->toBeFalse()
        ->and(CiJobFailure::factory()->make(['job_name' => 'Notify Slack (tests)'])->isTestJob())->toBeFalse();
});

it('scopes unresolved failures and failures for a job', function () {
    $open = CiJobFailure::factory()->create(['repo' => 'a/b', 'workflow_path' => '.github/workflows/ci.yml']);
    CiJobFailure::factory()->create(['repo' => 'a/b', 'workflow_path' => '.github/workflows/ci.yml', 'resolved_at' => now()]);
    CiJobFailure::factory()->create(['repo' => 'a/b', 'workflow_path' => '.github/workflows/other.yml']);

    expect(CiJobFailure::forJob('a/b', '.github/workflows/ci.yml')->unresolved()->pluck('id')->all())
        ->toBe([$open->id]);
});

it('links a claim to its task', function () {
    $task = YakTask::factory()->create();
    $claim = CiFailureClaim::factory()->create(['yak_task_id' => $task->id]);

    expect($claim->task->is($task))->toBeTrue()
        ->and($claim->fresh()->attempt_count)->toBe(0);
});

it('returns the latest default-branch green for a job by name', function () {
    CiDefaultBranchGreen::factory()->create([
        'repo' => 'a/b', 'workflow_name' => 'CI', 'job_name' => 'Test', 'completed_at' => '2026-11-01 10:00:00',
    ]);

    expect(CiDefaultBranchGreen::latestFor('a/b', 'CI', 'Test')?->toDateTimeString())->toBe('2026-11-01 10:00:00')
        ->and(CiDefaultBranchGreen::latestFor('a/b', 'CI', 'Lint'))->toBeNull();
});
