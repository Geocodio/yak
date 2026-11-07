<?php

use App\Channels\Slack\PreviewRepairMessenger;
use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\ReportPreviewFailureJob;
use App\Jobs\Deployments\UpdateDeploymentJob;
use App\Jobs\RunYakJob;
use App\Models\BranchDeployment;
use App\Models\Observation;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\DeploymentContainerManager;
use App\Services\PreviewManifestResolver;
use App\Services\PreviewRepair\PreviewFailureReporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->messenger = Mockery::mock(PreviewRepairMessenger::class)->shouldIgnoreMissing();
    $this->app->instance(PreviewRepairMessenger::class, $this->messenger);
    $this->repository = Repository::factory()->create(['deployments_enabled' => true]);
});

function failedPreview(Repository $repository, string $reason = 'deploy: Failed to start container: boom', array $attributes = []): BranchDeployment
{
    return BranchDeployment::factory()->for($repository)->failed($reason)->create(['current_commit_sha' => fake()->sha1(), ...$attributes]);
}

it('creates a repair task for the first failure', function () {
    $deployment = failedPreview($this->repository);

    $repair = app(PreviewFailureReporter::class)->report($deployment);

    $task = YakTask::sole();
    expect($repair)->not->toBeNull()
        ->and($repair->yak_task_id)->toBe($task->id)
        ->and($task->source)->toBe('preview-repair')
        ->and($task->external_id)->toBe("preview-repair-{$repair->id}")
        ->and(json_decode($task->context, true))->toMatchArray([
            'preview_repair_id' => $repair->id,
            'deployment_id' => $deployment->id,
            'phase' => 'lifecycle',
        ])
        ->and($repair->deployments->pluck('id')->all())->toBe([$deployment->id]);
    Queue::assertPushed(RunYakJob::class);
});

it('attaches a second failure with the same cause', function () {
    $reporter = app(PreviewFailureReporter::class);
    $first = $reporter->report(failedPreview($this->repository));
    $second = $reporter->report(failedPreview($this->repository));

    expect($second->id)->toBe($first->id)
        ->and(YakTask::count())->toBe(1)
        ->and($first->fresh()->deployments)->toHaveCount(2)
        ->and(Observation::where('kind', 'preview_repair.duplicate')->exists())->toBeTrue();
});

it('creates one repair for concurrent reports', function () {
    $reporter = app(PreviewFailureReporter::class);
    $reporter->report(failedPreview($this->repository));
    $reporter->report(failedPreview($this->repository));

    expect(PreviewRepair::count())->toBe(1)->and(YakTask::count())->toBe(1);
});

it('starts a separate repair for a different cause', function () {
    $reporter = app(PreviewFailureReporter::class);
    $reporter->report(failedPreview($this->repository, 'deploy: Failed to start container: boom'));
    $reporter->report(failedPreview($this->repository, 'deploy: Failed to clone template snapshot: gone'));

    expect(PreviewRepair::count())->toBe(2);
});

it('skips the preview of a repair task branch', function () {
    YakTask::factory()->create(['source' => 'preview-repair', 'repo' => $this->repository->slug, 'branch_name' => 'yak/preview-repair-1']);
    $deployment = failedPreview($this->repository, attributes: ['branch_name' => 'yak/preview-repair-1']);
    $this->messenger->shouldReceive('repairPreviewFailed')->once()->with(Mockery::on(fn ($given) => $given->is($deployment)));

    expect(app(PreviewFailureReporter::class)->report($deployment))->toBeNull()
        ->and(PreviewRepair::count())->toBe(0)
        ->and(Observation::where('kind', 'preview_repair.loop_guard')->exists())->toBeTrue();
});

it('skips a deployment already investigated at the same commit', function () {
    $deployment = failedPreview($this->repository);
    $repair = app(PreviewFailureReporter::class)->report($deployment);
    YakTask::query()->whereKey($repair->yak_task_id)->update(['status' => 'success']);
    $repair->update(['verdict' => 'branch']);

    expect(app(PreviewFailureReporter::class)->report($deployment->fresh()))->toBeNull()
        ->and(YakTask::count())->toBe(1)
        ->and(Observation::where('kind', 'preview_repair.already_investigated')->exists())->toBeTrue();
});

it('stops at three repair tasks per repository per day', function () {
    $this->messenger->shouldReceive('capReached')->once();
    $reporter = app(PreviewFailureReporter::class);

    foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $cause) {
        $reporter->report(failedPreview($this->repository, "deploy: cause {$cause}"));
    }

    expect(YakTask::count())->toBe(3)
        ->and(Observation::where('kind', 'preview_repair.capped')->exists())->toBeTrue();
});

it('does nothing when preview repair is switched off', function () {
    $this->repository->update(['preview_repair_enabled' => false]);

    expect(app(PreviewFailureReporter::class)->report(failedPreview($this->repository)))->toBeNull()
        ->and(YakTask::count())->toBe(0);
});

it('reports a transition to failed from the observer', function () {
    $deployment = BranchDeployment::factory()->for($this->repository)->starting()->create();

    $deployment->update(['status' => DeploymentStatus::Failed, 'failure_reason' => 'deploy: boom']);

    Queue::assertPushed(ReportPreviewFailureJob::class, fn (ReportPreviewFailureJob $job) => $job->deploymentId === $deployment->id);
});

it('reports a failed refresh of a running preview', function () {
    $deployment = BranchDeployment::factory()->for($this->repository)->running()->create();
    $manager = Mockery::mock(DeploymentContainerManager::class);
    $manager->shouldReceive('applyCheckoutRefresh')->andThrow(new RuntimeException('Command failed in container x (phase=refresh): exit 1'));

    expect(fn () => (new UpdateDeploymentJob($deployment->id, 'newsha'))->handle($manager))->toThrow(RuntimeException::class);

    Queue::assertPushed(ReportPreviewFailureJob::class, fn (ReportPreviewFailureJob $job) => $job->deploymentId === $deployment->id
        && str_contains((string) $job->exceptionMessage, 'exit 1')
        && $job->commitSha === 'newsha');
    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running);
});

it('records a failed refresh under the commit being refreshed to', function () {
    $deployment = BranchDeployment::factory()->for($this->repository)->running()->create(['current_commit_sha' => 'oldsha']);

    $repair = app(PreviewFailureReporter::class)->report($deployment, 'Command failed (phase=refresh): exit 1', 'newsha');

    expect(json_decode($repair->task->context, true)['head_sha'])->toBe('newsha')
        ->and($repair->deployments->first()->pivot->head_sha)->toBe('newsha');
});

it('investigates a new refresh failure on a later commit of the same preview', function () {
    $deployment = BranchDeployment::factory()->for($this->repository)->running()->create(['current_commit_sha' => 'oldsha']);
    $reporter = app(PreviewFailureReporter::class);
    $first = $reporter->report($deployment, 'Command failed (phase=refresh): first cause', 'secondsha');
    YakTask::query()->whereKey($first->yak_task_id)->update(['status' => 'success']);
    $first->update(['verdict' => 'branch']);

    $second = $reporter->report($deployment->fresh(), 'Command failed (phase=refresh): other cause', 'thirdsha');

    expect($second)->not->toBeNull()->and($second->id)->not->toBe($first->id);
});

it('resolves the preview recipe before locking the repository', function () {
    $resolver = Mockery::mock(PreviewManifestResolver::class);
    $transactionLevels = [];
    $resolver->shouldReceive('manifestFor')->andReturnUsing(function () use (&$transactionLevels) {
        $transactionLevels[] = DB::transactionLevel();

        return ['port' => 8090];
    });
    $this->app->instance(PreviewManifestResolver::class, $resolver);
    $baseLevel = DB::transactionLevel();

    app(PreviewFailureReporter::class)->report(failedPreview($this->repository));

    expect($transactionLevels)->toBe([$baseLevel]);
});
