<?php

use App\Enums\PreviewRepairVerdict;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\YakTask;

beforeEach(function () {
    $this->repository = Repository::factory()->create();
});

function repairWithTask(Repository $repository, array $taskAttributes, PreviewRepairVerdict $verdict = PreviewRepairVerdict::Pending, array $repairAttributes = []): PreviewRepair
{
    $task = YakTask::factory()->create(['repo' => $repository->slug, ...$taskAttributes]);

    return PreviewRepair::factory()->for($repository)->create([
        'fingerprint' => 'abc',
        'yak_task_id' => $task->id,
        'verdict' => $verdict,
        ...$repairAttributes,
    ]);
}

it('is live while its task is running', function () {
    $repair = repairWithTask($this->repository, ['status' => 'running']);

    expect(PreviewRepair::liveFor($this->repository, 'abc')?->id)->toBe($repair->id)
        ->and($repair->isLive())->toBeTrue();
});

it('is live while its recipe pull request is open', function () {
    $repair = repairWithTask($this->repository, ['status' => 'success', 'pr_url' => 'https://github.com/acme/site/pull/1'], PreviewRepairVerdict::Recipe);

    expect(PreviewRepair::liveFor($this->repository, 'abc')?->id)->toBe($repair->id);
});

it('is not live after its pull request merged', function () {
    repairWithTask($this->repository, ['status' => 'success', 'pr_url' => 'https://github.com/acme/site/pull/1', 'pr_merged_at' => now()], PreviewRepairVerdict::Recipe);

    expect(PreviewRepair::liveFor($this->repository, 'abc'))->toBeNull();
});

it('is not live after its pull request closed', function () {
    repairWithTask($this->repository, ['status' => 'success', 'pr_url' => 'https://github.com/acme/site/pull/1', 'pr_closed_at' => now()], PreviewRepairVerdict::Recipe);

    expect(PreviewRepair::liveFor($this->repository, 'abc'))->toBeNull();
});

it('is not live with a branch verdict once its task finished', function () {
    $repair = repairWithTask($this->repository, ['status' => 'success'], PreviewRepairVerdict::Branch);

    expect(PreviewRepair::liveFor($this->repository, 'abc'))->toBeNull()
        ->and($repair->isLive())->toBeFalse();
});

it('is not live when resolved', function () {
    repairWithTask($this->repository, ['status' => 'running'], PreviewRepairVerdict::Pending, ['resolved_at' => now()]);

    expect(PreviewRepair::liveFor($this->repository, 'abc'))->toBeNull();
});

it('is scoped to repository and fingerprint', function () {
    repairWithTask($this->repository, ['status' => 'running']);

    expect(PreviewRepair::liveFor($this->repository, 'other'))->toBeNull()
        ->and(PreviewRepair::liveFor(Repository::factory()->create(), 'abc'))->toBeNull();
});

it('enables preview repair on a repository by default', function () {
    expect(Repository::factory()->create()->fresh()->preview_repair_enabled)->toBeTrue();
});
