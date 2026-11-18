<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\CIBuildFailure;
use App\DataTransferObjects\CiFailureQualification;
use App\Enums\TaskStatus;
use App\Models\CiFailureClaim;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\CiFailure\CiFailureQualifier;
use App\Services\CiFailure\CiFailureTaskCreator;
use App\Services\ObservationRecorder;
use App\Services\TestFailureParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Records a failed CI job from a workflow_job webhook and, when the failure
 * is shared across branches and nothing already covers it, opens one fix task.
 */
class HandleCiJobFailureJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $workflowJobPayload  the full workflow_job webhook payload
     */
    public function __construct(
        public int $repositoryId,
        public array $workflowJobPayload,
    ) {}

    public function handle(AppService $github): void
    {
        $repository = Repository::find($this->repositoryId);

        if ($repository === null) {
            return;
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $job = (array) data_get($this->workflowJobPayload, 'workflow_job', []);
        $jobName = (string) ($job['name'] ?? '');

        $run = $github->getWorkflowRun($installationId, $repository->github_full_name, (int) ($job['run_id'] ?? 0));

        if ($run['head_repository_full_name'] !== $repository->github_full_name) {
            $this->declined($repository, 'ci_failure.fork_run', $run['path'] . ':' . $jobName, "{$jobName} failed in a run from a fork, so it is ignored.");

            return;
        }

        $failure = $this->recordFailure($github, $repository, $job, $run['path']);

        if ($failure === null) {
            return;
        }

        // Every GitHub call happens before the lock; the locked section only
        // touches the database, so the lock cannot expire mid-transaction.
        $qualification = app(CiFailureQualifier::class)->qualify($repository, $failure);

        if (! $qualification->qualifies) {
            if (in_array($qualification->reason, ['different_causes', 'shared_parent', 'stale_after_green', 'deferred_to_flaky_scan'], true)) {
                $this->declined($repository, 'ci_failure.' . $qualification->reason, $failure->job_name, "{$failure->job_name} failed on {$failure->branch}, but it does not qualify ({$qualification->reason}).");
            }

            return;
        }

        $gathered = app(CiFailureTaskCreator::class)->gather($repository, $qualification);
        $containsFix = $this->fixAncestry($github, $repository, $failure);

        $task = Cache::lock("ci-failure:{$repository->slug}", 30)->block(
            10,
            fn (): ?YakTask => DB::transaction(fn (): ?YakTask => $this->gate($repository, $failure, $qualification, $gathered, $containsFix)),
        );

        if ($task === null) {
            return;
        }

        ObservationRecorder::acted(
            source: CiFailureTaskCreator::SOURCE,
            kind: 'ci_failure.task_created',
            summary: "{$failure->job_name} is failing across branches; opened task #{$task->id}.",
            repo: $repository->slug,
            subject: $failure->job_name,
            referenceUrl: $task->external_url,
            task: $task,
        );

        app(AgentJobDispatcher::class)->dispatch($task, RunYakJob::class);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function recordFailure(AppService $github, Repository $repository, array $job, string $workflowPath): ?CiJobFailure
    {
        $existing = CiJobFailure::where('job_id', (int) $job['id'])->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var list<array<string, mixed>> $steps */
        $steps = (array) ($job['steps'] ?? []);
        $failedStep = collect($steps)->firstWhere('conclusion', 'failure')['name'] ?? null;

        // A job with needs and no failing step never ran: it failed only
        // because a job it depends on failed.
        if ($failedStep === null && ! empty($job['needs'])) {
            return null;
        }

        $failure = new CiJobFailure([
            'repo' => $repository->slug,
            'workflow_path' => $workflowPath,
            'workflow_name' => (string) ($job['workflow_name'] ?? ''),
            'job_name' => (string) ($job['name'] ?? ''),
            'failed_step' => $failedStep,
            'test_names' => null,
            'branch' => (string) ($job['head_branch'] ?? ''),
            'head_sha' => (string) ($job['head_sha'] ?? ''),
            'job_id' => (int) $job['id'],
            'run_id' => (int) ($job['run_id'] ?? 0),
            'job_url' => (string) ($job['html_url'] ?? ''),
            'actor_login' => (string) data_get($this->workflowJobPayload, 'sender.login', ''),
            'failed_at' => $job['completed_at'] ?? now(),
        ]);

        if ($failure->isTestJob()) {
            $log = $github->getJobLogExcerpt(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                $failure->job_id,
                (int) config('yak.ci_failure.log_excerpt_lines'),
            );

            $failure->test_names = $log === null ? [] : array_values(array_unique(array_map(
                fn (array $parsed): string => CIBuildFailure::normalizeTestName($parsed['test']),
                app(TestFailureParser::class)->parse($log),
            )));
        }

        $failure->save();

        return $failure;
    }

    /**
     * Whether the failure's head contains each live claim's fix merge sha.
     *
     * @return array<string, bool>
     */
    private function fixAncestry(AppService $github, Repository $repository, CiJobFailure $failure): array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $ancestry = [];

        foreach (CiFailureClaim::liveFor($repository->slug)->pluck('fix_merge_sha')->filter()->unique() as $sha) {
            $ancestry[$sha] = $github->isAncestor($installationId, $repository->github_full_name, $sha, $failure->head_sha);
        }

        return $ancestry;
    }

    /**
     * Database-only: runs under the repository lock.
     *
     * @param  array{excerpts: array<int, string|null>, author: string|null}  $gathered
     * @param  array<string, bool>  $containsFix
     */
    private function gate(Repository $repository, CiJobFailure $failure, CiFailureQualification $qualification, array $gathered, array $containsFix): ?YakTask
    {
        $stillUnresolved = CiJobFailure::query()
            ->unresolved()
            ->whereIn('id', $qualification->evidence->pluck('id'))
            ->count();

        if ($stillUnresolved !== $qualification->evidence->count()) {
            return null;
        }

        $keys = $this->claimKeys($failure, $qualification);

        $inFlight = YakTask::query()
            ->where('repo', $repository->slug)
            ->where('source', CiFailureTaskCreator::SOURCE)
            ->whereIn('status', TaskStatus::activeValues())
            ->whereNull('pr_url')
            ->first();

        if ($inFlight !== null) {
            foreach ($keys as $key) {
                CiFailureClaim::firstOrCreate(
                    ['repo' => $repository->slug, 'workflow_path' => $failure->workflow_path, 'yak_task_id' => $inFlight->id] + $key,
                    ['created_at' => now()],
                );
            }

            $this->declined($repository, 'ci_failure.task_in_flight', $failure->job_name, "{$failure->job_name} is failing, but Yak is already fixing another CI failure.", $inFlight);

            return null;
        }

        $claim = $this->liveClaim($repository, $failure, $keys, $containsFix);

        if ($claim !== null) {
            $this->declined($repository, 'ci_failure.already_claimed', $failure->job_name, "{$failure->job_name} is still failing, but Yak already has it covered.", $claim->task, $claim->skipped_pr_url);

            return null;
        }

        $task = app(CiFailureTaskCreator::class)->create($repository, $qualification, $gathered);

        if ($task === null) {
            return null;
        }

        foreach ($keys as $key) {
            CiFailureClaim::create([
                'repo' => $repository->slug,
                'workflow_path' => $failure->workflow_path,
                'yak_task_id' => $task->id,
                'created_at' => now(),
            ] + $key);
        }

        return $task;
    }

    /**
     * One claim key per test class for a test job with known tests, otherwise
     * one for the job itself.
     *
     * @return list<array{job_name: string, test_class: string|null}>
     */
    private function claimKeys(CiJobFailure $failure, CiFailureQualification $qualification): array
    {
        $testNames = $failure->isTestJob() ? CiFailureTaskCreator::sharedTestNames($qualification->evidence) : [];

        if ($testNames === []) {
            return [['job_name' => $failure->job_name, 'test_class' => null]];
        }

        return array_map(
            fn (string $class): array => ['job_name' => $failure->job_name, 'test_class' => $class],
            array_values(array_unique(array_map(CIBuildFailure::normalizeTestClass(...), $testNames))),
        );
    }

    /**
     * @param  list<array{job_name: string, test_class: string|null}>  $keys
     * @param  array<string, bool>  $containsFix
     */
    private function liveClaim(Repository $repository, CiJobFailure $failure, array $keys, array $containsFix): ?CiFailureClaim
    {
        return CiFailureClaim::liveFor($repository->slug)
            ->filter(fn (CiFailureClaim $claim): bool => $claim->workflow_path === $failure->workflow_path
                && collect($keys)->contains(fn (array $key): bool => $key['test_class'] !== null
                    ? $claim->test_class === $key['test_class']
                    : $claim->test_class === null && $claim->job_name === $key['job_name']))
            ->first(fn (CiFailureClaim $claim): bool => ! $claim->isLiveOnlyByFixMergeSha()
                || ! ($claim->fix_merge_sha !== null && ($containsFix[$claim->fix_merge_sha] ?? false)));
    }

    private function declined(Repository $repository, string $kind, string $subject, string $summary, ?YakTask $task = null, ?string $referenceUrl = null): void
    {
        if (! Cache::add("ci-failure-observed:{$repository->slug}:{$kind}:{$subject}", true, 3600)) {
            return;
        }

        ObservationRecorder::declined(
            source: CiFailureTaskCreator::SOURCE,
            kind: $kind,
            summary: $summary,
            repo: $repository->slug,
            subject: $subject,
            referenceUrl: $referenceUrl,
            task: $task,
            dedupeWithinHours: null,
        );
    }
}
