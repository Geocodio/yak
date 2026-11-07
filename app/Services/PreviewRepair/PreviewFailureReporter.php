<?php

namespace App\Services\PreviewRepair;

use App\Channels\Slack\PreviewRepairMessenger;
use App\Enums\PreviewRepairVerdict;
use App\Jobs\RunYakJob;
use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\ObservationRecorder;
use App\Services\PreviewManifestResolver;
use App\Services\ResponsiblePersonResolver;
use App\Services\TaskLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Decides what happens when a preview fails: attach it to the live repair
 * of the same cause, or start a new investigation task.
 *
 * One cause gets one task, however many previews it breaks. A repair
 * task's own preview never starts another task, a preview already
 * investigated at the same commit is not investigated again, and a
 * repository gets at most DAILY_CAP_PER_REPOSITORY new tasks a day.
 */
class PreviewFailureReporter
{
    public const SOURCE = 'preview-repair';

    public const DAILY_CAP_PER_REPOSITORY = 3;

    public function __construct(
        private readonly PreviewRepairMessenger $messenger,
        private readonly AgentJobDispatcher $dispatcher,
        private readonly PreviewManifestResolver $manifests,
        private readonly ResponsiblePersonResolver $responsiblePeople,
    ) {}

    public function report(BranchDeployment $deployment, ?string $exceptionMessage = null): ?PreviewRepair
    {
        $repository = $deployment->repository;

        if (! $repository->deployments_enabled || ! $repository->preview_repair_enabled) {
            return null;
        }

        if ($this->isRepairTaskBranch($repository, $deployment)) {
            $this->decline($repository, $deployment, 'preview_repair.loop_guard', "The preview of repair branch {$deployment->branch_name} failed; no new investigation.");
            $this->messenger->repairPreviewFailed($deployment);

            return null;
        }

        $fingerprint = PreviewFailureFingerprint::for($deployment, $exceptionMessage);
        $capReached = false;

        /** @var array{0: PreviewRepair|null, 1: YakTask|null} $outcome */
        $outcome = DB::transaction(function () use ($repository, $deployment, $fingerprint, $exceptionMessage, &$capReached): array {
            Repository::query()->whereKey($repository->id)->lockForUpdate()->first();

            $live = PreviewRepair::liveFor($repository, $fingerprint->hash);

            if ($live !== null) {
                $this->attach($live, $deployment);
                $live->update(['last_failed_at' => now()]);
                $this->decline($repository, $deployment, 'preview_repair.duplicate', "The preview for {$deployment->branch_name} failed for the same reason as repair #{$live->id}.");

                return [$live, null];
            }

            if ($this->wasInvestigated($deployment)) {
                $this->decline($repository, $deployment, 'preview_repair.already_investigated', "The preview for {$deployment->branch_name} was already investigated at this commit.");

                return [null, null];
            }

            if ($this->tasksStartedToday($repository) >= self::DAILY_CAP_PER_REPOSITORY) {
                $this->decline($repository, $deployment, 'preview_repair.capped', "Preview investigations for {$repository->slug} reached the daily limit.");
                $capReached = true;

                return [null, null];
            }

            $previewRepair = PreviewRepair::create([
                'repository_id' => $repository->id,
                'fingerprint' => $fingerprint->hash,
                'phase' => $fingerprint->phase,
                'error_excerpt' => $fingerprint->excerpt,
                'verdict' => PreviewRepairVerdict::Pending,
                'first_failed_at' => now(),
                'last_failed_at' => now(),
            ]);

            $task = $this->createTask($previewRepair, $deployment, $fingerprint, $exceptionMessage);
            $previewRepair->update(['yak_task_id' => $task->id]);
            $this->attach($previewRepair, $deployment);

            return [$previewRepair, $task];
        });

        [$previewRepair, $task] = $outcome;

        if ($capReached && Cache::add("preview-repair-cap:{$repository->id}:" . today()->toDateString(), true, now()->endOfDay())) {
            $this->messenger->capReached($repository);
        }

        if ($task !== null && $previewRepair !== null) {
            TaskLogger::info($task, 'Task created', ['source' => self::SOURCE, 'repo' => $repository->slug]);
            $this->dispatcher->dispatch($task, RunYakJob::class);

            ObservationRecorder::acted(
                source: self::SOURCE,
                kind: 'preview_repair.task_created',
                summary: "The preview for {$deployment->branch_name} failed in {$previewRepair->phase}; opened task #{$task->id}.",
                repo: $repository->slug,
                subject: "deployment:{$deployment->id}",
                referenceUrl: $task->external_url,
                task: $task,
            );
        }

        return $previewRepair;
    }

    private function isRepairTaskBranch(Repository $repository, BranchDeployment $deployment): bool
    {
        return YakTask::query()
            ->where('source', self::SOURCE)
            ->where('repo', $repository->slug)
            ->where('branch_name', $deployment->branch_name)
            ->exists();
    }

    private function wasInvestigated(BranchDeployment $deployment): bool
    {
        return $deployment->current_commit_sha !== null && DB::table('preview_repair_deployments')
            ->where('branch_deployment_id', $deployment->id)
            ->where('head_sha', $deployment->current_commit_sha)
            ->exists();
    }

    private function tasksStartedToday(Repository $repository): int
    {
        return YakTask::query()
            ->where('source', self::SOURCE)
            ->where('repo', $repository->slug)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    private function attach(PreviewRepair $previewRepair, BranchDeployment $deployment): void
    {
        $previewRepair->deployments()->syncWithoutDetaching([
            $deployment->id => ['head_sha' => $deployment->current_commit_sha, 'failed_at' => now()],
        ]);
    }

    private function createTask(PreviewRepair $previewRepair, BranchDeployment $deployment, PreviewFailureFingerprint $fingerprint, ?string $exceptionMessage): YakTask
    {
        $slug = $deployment->repository->slug;
        $failingCommand = $deployment->logs()->where('level', 'error')->where('phase', $fingerprint->phase)->latest('id')->value('message');

        return YakTask::create([
            'source' => self::SOURCE,
            'repo' => $slug,
            'external_id' => "preview-repair-{$previewRepair->id}",
            'external_url' => route('deployments.show', $deployment),
            'description' => "Preview for {$deployment->branch_name} failed in {$fingerprint->phase}",
            'context' => json_encode([
                'preview_repair_id' => $previewRepair->id,
                'deployment_id' => $deployment->id,
                'branch_name' => $deployment->branch_name,
                'pr_number' => $deployment->pr_number,
                'head_sha' => $deployment->current_commit_sha,
                'phase' => $fingerprint->phase,
                'failure_reason' => $exceptionMessage ?? $deployment->failure_reason,
                'error_excerpt' => $fingerprint->excerpt,
                'failing_command' => $failingCommand,
                'manifest' => $this->manifests->manifestFor($deployment),
                'template_version' => $deployment->template_version,
            ]),
            'mode' => 'fix',
            'responsible_name' => $this->responsiblePeople->resolve(null, null, $slug),
            'responsible_user_id' => $this->responsiblePeople->resolveUser(null, null, $slug)?->id,
        ]);
    }

    private function decline(Repository $repository, BranchDeployment $deployment, string $kind, string $summary): void
    {
        ObservationRecorder::declined(
            source: self::SOURCE,
            kind: $kind,
            summary: $summary,
            repo: $repository->slug,
            subject: "deployment:{$deployment->id}",
        );
    }
}
