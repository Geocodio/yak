<?php

use App\Actions\RecordPullRequestOutcome;
use App\Channels\Slack\PreviewRepairMessenger;
use App\Jobs\Deployments\RebuildDeploymentJob;
use App\Jobs\Deployments\ResolvePreviewRepairJob;
use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\Repository;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->messenger = Mockery::mock(PreviewRepairMessenger::class)->shouldIgnoreMissing();
    $this->app->instance(PreviewRepairMessenger::class, $this->messenger);

    $this->repository = Repository::factory()->create();
    $this->previewRepair = PreviewRepair::factory()->for($this->repository)->recipeWithOpenPullRequest()->create();
    $this->previewRepair->task->update(['pr_url' => 'https://github.com/acme/site/pull/101', 'repo' => $this->repository->slug]);
});

function attachRepairPreview(PreviewRepair $previewRepair, BranchDeployment $deployment): BranchDeployment
{
    $previewRepair->deployments()->attach($deployment->id, ['failed_at' => now()]);

    return $deployment;
}

it('rebuilds attached failed previews when the repair merges', function () {
    Queue::fake([RebuildDeploymentJob::class]);
    $failed = attachRepairPreview($this->previewRepair, BranchDeployment::factory()->for($this->repository)->failed()->create());
    $this->messenger->shouldReceive('rebuilt')->once()->with(
        Mockery::on(fn ($given) => $given->is($this->previewRepair)),
        [$failed->id],
    );

    (new ResolvePreviewRepairJob($this->previewRepair->id))->handle(app(PreviewRepairMessenger::class));

    Queue::assertPushed(RebuildDeploymentJob::class, fn (RebuildDeploymentJob $job) => $job->deploymentId === $failed->id);
    expect($this->previewRepair->fresh()->resolved_at)->not->toBeNull();
});

it('leaves running and destroyed previews alone', function () {
    Queue::fake([RebuildDeploymentJob::class]);
    attachRepairPreview($this->previewRepair, BranchDeployment::factory()->for($this->repository)->running()->create());
    attachRepairPreview($this->previewRepair, BranchDeployment::factory()->for($this->repository)->destroyed()->create());

    (new ResolvePreviewRepairJob($this->previewRepair->id))->handle(app(PreviewRepairMessenger::class));

    Queue::assertNotPushed(RebuildDeploymentJob::class);
});

it('starts resolving when the repair pull request is recorded as merged', function () {
    Queue::fake([ResolvePreviewRepairJob::class]);

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/site/pull/101', merged: true);

    Queue::assertPushed(ResolvePreviewRepairJob::class, fn (ResolvePreviewRepairJob $job) => $job->previewRepairId === $this->previewRepair->id);
});

it('does nothing when the repair pull request is closed without merging', function () {
    Queue::fake([ResolvePreviewRepairJob::class]);

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/site/pull/101', merged: false);

    Queue::assertNotPushed(ResolvePreviewRepairJob::class);
});

it('runs once when the outcome is recorded twice', function () {
    Queue::fake([ResolvePreviewRepairJob::class]);

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/site/pull/101', merged: true);
    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/site/pull/101', merged: true, via: 'reconciler');

    Queue::assertPushed(ResolvePreviewRepairJob::class, 1);
});
