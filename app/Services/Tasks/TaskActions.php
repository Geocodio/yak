<?php

namespace App\Services\Tasks;

use App\Actions\DispatchRepositorySetupTask;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Console\Commands\TimeoutAwaitingCiCommand;
use App\DataTransferObjects\TaskActionOutcome;
use App\DataTransferObjects\TaskStop;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Exceptions\ThreadActionRefused;
use App\Facades\Telemetry;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\Artifact;
use App\Models\PendingSteeringMessage;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\Devices\ShortLockWait;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use App\Services\Threads\ThreadRetryReset;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The task actions both clients call. Each one locks the task row for at most
 * five seconds, reads the status again inside the lock and changes it once, so
 * a repeated request finds the state already reached and does nothing. Jobs,
 * notifications, sandbox and GitHub calls run after the lock is released.
 */
class TaskActions
{
    private const RENDER_REQUEST_LIFETIME_MINUTES = 30;

    /** @var list<TaskStatus> */
    private const CANCELLABLE = [
        TaskStatus::Pending,
        TaskStatus::Running,
        TaskStatus::AwaitingClarification,
        TaskStatus::AwaitingCi,
        TaskStatus::Retrying,
    ];

    public function __construct(private readonly AgentJobDispatcher $dispatcher) {}

    /**
     * @param  bool  $isTimeBounded  fail fast on every GitHub call (2 s connect, 3 s total), for the app that waits on the answer
     */
    public function retry(YakTask $task, bool $isTimeBounded = false): TaskActionOutcome
    {
        // Only reads GitHub. The rerun of failed jobs happens after the claim, so one request does it.
        $ciResume = in_array($task->status, [TaskStatus::Failed, TaskStatus::Expired], true) ? $this->readCiAfterTimeout($task, $isTimeBounded) : null;

        /** @var array{outcome: TaskActionOutcome, after: ?Closure} $result */
        $result = $this->locked($task, function (YakTask $locked) use ($ciResume, $isTimeBounded): array {
            if (in_array($locked->status, [TaskStatus::Pending, TaskStatus::Running, TaskStatus::Retrying, TaskStatus::AwaitingCi], true)) {
                return ['outcome' => new TaskActionOutcome('queued', 'Task is already queued.'), 'after' => null];
            }

            if (! in_array($locked->status, [TaskStatus::Failed, TaskStatus::Expired], true)) {
                throw ThreadActionRefused::because(ThreadActionRefused::TASK_NOT_RETRYABLE, 'This task cannot be retried right now.');
            }

            $original = Arr::only($locked->getRawOriginal(), ['status', 'error_log', 'completed_at', 'started_at', 'attempts', 'attempts_at_manual_retry']);

            if ($ciResume !== null) {
                return $this->claimCiResume($locked, $ciResume, $original, $isTimeBounded);
            }

            if ($this->hasPushedBranch($locked)) {
                return $this->claimRetryOnBranch($locked, $original);
            }

            return $this->claimFreshRetry($locked);
        });

        if ($result['after'] !== null) {
            ($result['after'])();
        }

        return $result['outcome'];
    }

    /**
     * Marks a failed task to continue once its repository's setup finishes, and starts
     * that setup unless one is already pending or running.
     */
    public function setUpRepositoryAndContinue(YakTask $task, User $user): TaskActionOutcome
    {
        $repository = Repository::where('slug', $task->repo)->first();

        if ($repository === null || ! empty($repository->sandbox_snapshot)) {
            throw ThreadActionRefused::because(ThreadActionRefused::TASK_NOT_RETRYABLE, 'This repository is already set up. Retry the task instead.');
        }

        $this->locked($task, function (YakTask $locked): void {
            if (! in_array($locked->status, [TaskStatus::Failed, TaskStatus::Expired], true)) {
                throw ThreadActionRefused::because(ThreadActionRefused::TASK_NOT_RETRYABLE, 'This task cannot be retried right now.');
            }

            $locked->update(['resume_after_setup_at' => now()]);
        });

        if (! in_array($repository->setup_status, ['pending', 'running'], true)) {
            app(DispatchRepositorySetupTask::class)($repository, $user);
        }

        return new TaskActionOutcome('queued', "Setting up {$repository->slug}. This task continues when setup finishes.");
    }

    /**
     * Retries the tasks that waited on this repository's setup once it is ready. A failed
     * setup clears the wait, so the task shows the failure and its owner chooses again.
     * Runs inside the setup job's repository update, so a failed retry is reported, never thrown.
     */
    public function continueTasksAwaitingSetup(Repository $repository): void
    {
        if (! in_array($repository->setup_status, ['ready', 'failed'], true)) {
            return;
        }

        YakTask::withoutGlobalScopes()
            ->where('repo', $repository->slug)
            ->whereNotNull('resume_after_setup_at')
            ->get()
            ->each(function (YakTask $task) use ($repository): void {
                $task->update(['resume_after_setup_at' => null]);

                if ($repository->setup_status !== 'ready') {
                    if ($task->status !== TaskStatus::Failed) {
                        return;
                    }

                    $setupTask = $repository->setup_task_id === null ? null : YakTask::withoutGlobalScopes()->find($repository->setup_task_id);

                    StopNotifier::notify($task, TaskStop::setupFailed($repository->slug, $setupTask?->error_log ?: 'Setup failed'));

                    return;
                }

                try {
                    $this->retry($task);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }

    public function cancel(YakTask $task, User $canceller): TaskActionOutcome
    {
        /** @var ?TaskStatus $statusBefore */
        $statusBefore = $this->locked($task, function (YakTask $locked): ?TaskStatus {
            if ($locked->status === TaskStatus::Cancelled) {
                return null;
            }

            if (! in_array($locked->status, self::CANCELLABLE, true)) {
                throw ThreadActionRefused::because(ThreadActionRefused::TASK_NOT_CANCELLABLE, 'This task cannot be cancelled right now.');
            }

            $statusBefore = $locked->status;

            TaskLogger::info($locked, 'Task cancelled by user');
            $locked->update(['status' => TaskStatus::Cancelled, 'completed_at' => now()]);

            return $statusBefore;
        });

        if ($statusBefore === null) {
            return new TaskActionOutcome('cancelled', 'Task already cancelled.');
        }

        Telemetry::feature('dashboard.cancel', ['status' => $statusBefore->value], task: $task);

        try {
            app(IncusSandboxManager::class)->destroy('task-' . $task->id);
        } catch (Throwable $e) {
            Log::channel('yak')->warning('Sandbox destroy failed during cancel', ['task_id' => $task->id, 'error' => $e->getMessage()]);
        }

        SendNotificationJob::dispatch(
            $task,
            NotificationType::Cancelled,
            'Task cancelled from the dashboard by ' . ($canceller->name ?? 'someone') . '.',
            actingUser: $canceller,
        );

        if ($task->source === 'linear') {
            $linear = app(LinearNotificationDriver::class);
            $cancelledStateId = (string) config('yak.channels.linear.cancelled_state_id');

            if ($cancelledStateId !== '') {
                $linear->setIssueState($task, $cancelledStateId);
            }

            $linear->syncSessionPlan($task);
        }

        return new TaskActionOutcome('cancelled', 'Task cancelled.');
    }

    public function rerunReview(YakTask $task, bool $isTimeBounded = false): TaskActionOutcome
    {
        $this->assertReviewCanRerun($task);

        $installationId = (int) config('yak.channels.github.installation_id');
        $prNumber = (json_decode((string) $task->context, true) ?: [])['pr_number'] ?? null;

        if ($prNumber === null) {
            throw ThreadActionRefused::because(ThreadActionRefused::PULL_REQUEST_UNKNOWN, 'Cannot determine PR number.');
        }

        try {
            $pullRequest = app(GitHubAppService::class)->getPullRequest($installationId, (string) $task->repo, (int) $prNumber, $isTimeBounded);
        } catch (Throwable $e) {
            Log::channel('yak')->warning('Could not fetch the pull request to re-run a review', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            $pullRequest = [];
        }

        if (! isset($pullRequest['head']['sha'])) {
            throw new ThreadActionRefused(ThreadActionRefused::GITHUB_UNAVAILABLE, 'Failed to fetch PR from GitHub.', [], 503);
        }

        $statusBefore = $this->locked($task, function (YakTask $locked) use ($pullRequest): TaskStatus {
            if (in_array($locked->status, [TaskStatus::Pending, TaskStatus::Running], true)) {
                throw ThreadActionRefused::because(ThreadActionRefused::REVIEW_ALREADY_QUEUED, 'A review is already queued for this PR.');
            }

            /** @var TaskStatus $statusBefore */
            $statusBefore = $locked->status;

            PrReview::where('yak_task_id', $locked->id)->delete();

            // Raw update because Success is a final state the enum will not
            // transition out of. Lifetime cost/turn totals are kept on purpose.
            DB::table('tasks')->where('id', $locked->id)->update([
                'status' => TaskStatus::Pending->value,
                'error_log' => null,
                'result_summary' => null,
                'pr_body_update' => null,
                'review_replies' => null,
                'started_at' => null,
                'completed_at' => null,
                'branch_name' => (string) $pullRequest['head']['ref'],
                'context' => json_encode([
                    'pr_number' => (int) $pullRequest['number'],
                    'head_sha' => (string) $pullRequest['head']['sha'],
                    'head_ref' => (string) $pullRequest['head']['ref'],
                    'base_sha' => (string) $pullRequest['base']['sha'],
                    'base_ref' => (string) $pullRequest['base']['ref'],
                    'author' => (string) ($pullRequest['user']['login'] ?? ''),
                    'title' => (string) ($pullRequest['title'] ?? ''),
                    'body' => (string) ($pullRequest['body'] ?? ''),
                    'review_scope' => 'full',
                    'incremental_base_sha' => null,
                ]),
                'updated_at' => now(),
            ]);

            return $statusBefore;
        });

        $task->refresh();

        TaskStatusChanged::dispatch($task, $statusBefore, TaskStatus::Pending);
        Telemetry::feature('dashboard.rerun_review', [], task: $task);

        $this->dispatcher->dispatch($task, RunYakReviewJob::class);

        return new TaskActionOutcome('queued', 'Re-running review for this PR.');
    }

    public function retryRender(YakTask $task): TaskActionOutcome
    {
        $rawFootageId = $this->locked($task, function (YakTask $locked): ?int {
            $rawFootage = $locked->artifacts()->rawFootage()->latest('id')->first();

            if ($rawFootage === null) {
                throw ThreadActionRefused::because(ThreadActionRefused::NOTHING_TO_RENDER, 'Nothing to re-render for this task.');
            }

            $isClaimed = Artifact::query()
                ->whereKey($rawFootage->id)
                ->where(fn ($query) => $query->whereNull('render_requested_at')->orWhere('render_requested_at', '<', now()->subMinutes(self::RENDER_REQUEST_LIFETIME_MINUTES)))
                ->update(['render_requested_at' => now()]) === 1;

            return $isClaimed ? $rawFootage->id : null;
        });

        if ($rawFootageId !== null) {
            try {
                RenderVideoJob::dispatch($rawFootageId);
            } catch (Throwable $e) {
                report($e);
                Artifact::query()->whereKey($rawFootageId)->update(['render_requested_at' => null]);

                throw new HttpException(503, 'Yak could not start the render. Try again in a moment.');
            }
        }

        return new TaskActionOutcome('rendering', 'Re-rendering the walkthrough.');
    }

    public function reroute(YakTask $task, string $repoSlug): TaskActionOutcome
    {
        /** @var array{from: string, to: string, statusBefore: TaskStatus, wasInFlight: bool}|null $moved */
        $moved = $this->locked($task, function (YakTask $locked) use ($repoSlug): ?array {
            $canReroute = ! in_array($locked->mode, [TaskMode::Setup, TaskMode::Review], true) && $locked->pr_url === null;

            if (! $canReroute) {
                throw ThreadActionRefused::because(ThreadActionRefused::TASK_NOT_REROUTABLE, 'This task cannot be moved to another repo.');
            }

            $newRepo = Repository::where('slug', $repoSlug)->where('is_active', true)->first()
                ?? throw ValidationException::withMessages(['repo' => 'Repository not found or inactive.']);

            if ($newRepo->slug === (string) $locked->repo) {
                return null;
            }

            /** @var TaskStatus $statusBefore */
            $statusBefore = $locked->status;
            $from = (string) $locked->repo;

            // Raw update: the enum forbids some of these transitions. Lifetime
            // cost/turn totals are kept on purpose.
            DB::table('tasks')->where('id', $locked->id)->update([
                'repo' => $newRepo->slug,
                'status' => TaskStatus::Pending->value,
                'branch_name' => null,
                'error_log' => null,
                'result_summary' => null,
                'pr_body_update' => null,
                'review_replies' => null,
                'started_at' => null,
                'completed_at' => null,
                'updated_at' => now(),
            ]);

            return [
                'from' => $from,
                'to' => $newRepo->slug,
                'statusBefore' => $statusBefore,
                'wasInFlight' => in_array($statusBefore, [TaskStatus::Running, TaskStatus::AwaitingClarification, TaskStatus::AwaitingCi, TaskStatus::Retrying], true),
            ];
        });

        if ($moved === null) {
            return new TaskActionOutcome('moved', '');
        }

        if ($moved['wasInFlight']) {
            try {
                app(IncusSandboxManager::class)->destroy('task-' . $task->id);
            } catch (Throwable $e) {
                Log::channel('yak')->warning('Sandbox destroy failed during reroute', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            }
        }

        $task->refresh();

        TaskStatusChanged::dispatch($task, $moved['statusBefore'], TaskStatus::Pending);
        Telemetry::feature('dashboard.reroute', ['from' => $moved['from'], 'to' => $moved['to']], task: $task);

        TaskLogger::info($task, "Task rerouted from {$moved['from']} to {$moved['to']}");

        $this->dispatcher->dispatch($task, $task->mode === TaskMode::Research ? ResearchYakJob::class : RunYakJob::class);

        SendNotificationJob::dispatch(
            $task,
            NotificationType::Retry,
            "Moved from {$moved['from']} to {$moved['to']} — restarting there.",
        );

        return new TaskActionOutcome('moved', "Task moved to {$moved['to']}.");
    }

    /**
     * @template TReturn
     *
     * @param  Closure(YakTask): TReturn  $callback  runs with the task row locked and its status read again
     * @return TReturn
     */
    private function locked(YakTask $task, Closure $callback): mixed
    {
        return ShortLockWait::transaction(function () use ($task, $callback) {
            $locked = YakTask::withoutGlobalScopes()->whereKey($task->id)->lockForUpdate()->first()
                ?? throw new NotFoundHttpException;

            return $callback($locked);
        }, fn (): Throwable => new HttpException(503, 'Yak is still handling another request for this task. Try again in a moment.'));
    }

    private function assertReviewCanRerun(YakTask $task): void
    {
        if ($task->mode !== TaskMode::Review) {
            throw ThreadActionRefused::because(ThreadActionRefused::NOT_A_REVIEW, 'This task is not a review.');
        }

        if (in_array($task->status, [TaskStatus::Pending, TaskStatus::Running], true)) {
            throw ThreadActionRefused::because(ThreadActionRefused::REVIEW_ALREADY_QUEUED, 'A review is already queued for this PR.');
        }
    }

    /**
     * @return array{outcome: TaskActionOutcome, after: ?Closure}
     */
    private function claimFreshRetry(YakTask $task): array
    {
        /** @var TaskMode $mode */
        $mode = $task->mode;

        // cost_usd, duration_ms and num_turns are lifetime totals and
        // deliberately survive a retry: the failed attempt still cost money.
        // The claim adds one to attempts for this pass, the same way it does
        // for a new task, so the task gets the full CI-retry budget again.
        $task->update([
            'status' => TaskStatus::Pending,
            'description' => $this->withQueuedReplies($task, $mode),
            'attempts_at_manual_retry' => $task->attempts,
            'error_log' => null,
            'result_summary' => null,
            'pr_body_update' => null,
            'review_replies' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $jobClass = match ($mode) {
            TaskMode::Setup => SetupYakJob::class,
            TaskMode::Research => $task->parent_task_id !== null ? ResearchFollowUpJob::class : ResearchYakJob::class,
            TaskMode::Review => RunYakReviewJob::class,
            default => RunYakJob::class,
        };

        return [
            'outcome' => new TaskActionOutcome('queued', 'Task re-queued.'),
            'after' => function () use ($task, $mode, $jobClass): void {
                if ($jobClass === RunYakJob::class) {
                    app(ThreadRetryReset::class)->prepare($task);
                }

                Telemetry::feature('dashboard.retry', ['mode' => $mode->value], task: $task);

                $this->dispatcher->dispatch($task, $jobClass);
            },
        ];
    }

    /**
     * The same pass Yak takes after a red build: the agent gets the previous
     * summary and the CI failure, and pushes on top of the earlier commits.
     *
     * @param  array<string, mixed>  $original  the raw task columns the claim changes, put back when the job cannot be queued
     * @return array{outcome: TaskActionOutcome, after: ?Closure}
     */
    private function claimRetryOnBranch(YakTask $task, array $original): array
    {
        $failureOutput = $task->error_log;

        // This pass counts as the first attempt of a fresh CI-retry budget.
        $task->update([
            'status' => TaskStatus::Retrying,
            'attempts' => $task->attempts + 1,
            'attempts_at_manual_retry' => $task->attempts,
            'error_log' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        TaskLogger::info($task, 'Retry requested from the dashboard, continuing on the existing branch', ['branch' => $task->branch_name]);

        return [
            'outcome' => new TaskActionOutcome('queued', "Retrying on {$task->branch_name}."),
            'after' => function () use ($task, $failureOutput, $original): void {
                Telemetry::feature('dashboard.retry', ['mode' => TaskMode::Fix->value, 'resumed_branch' => true], task: $task);

                $this->runOrReleaseClaim($task, $original, fn () => RetryYakJob::dispatch($task, $failureOutput));
            },
        ];
    }

    /**
     * @param  array{runs: list<array{id: int, head_sha: string, status: ?string, conclusion: ?string}>, pending: int, failed: int}  $ciResume
     * @param  array<string, mixed>  $original  the raw task columns the claim changes, put back when GitHub or the queue refuses the next step
     * @return array{outcome: TaskActionOutcome, after: ?Closure}
     */
    private function claimCiResume(YakTask $task, array $ciResume, array $original, bool $isTimeBounded): array
    {
        $task->update(['status' => TaskStatus::Retrying]);
        $task->update(['status' => TaskStatus::AwaitingCi, 'error_log' => null, 'completed_at' => null]);

        TaskLogger::info($task, TimeoutAwaitingCiCommand::CI_RESUMED_LOG_MESSAGE, [
            'branch' => $task->branch_name,
            'still_running' => $ciResume['pending'],
            'rerun_failed' => $ciResume['pending'] === 0 ? $ciResume['failed'] : 0,
        ]);

        return [
            'outcome' => new TaskActionOutcome('queued', "Checking CI on {$task->branch_name} again."),
            'after' => function () use ($task, $ciResume, $original, $isTimeBounded): void {
                Telemetry::feature('dashboard.retry', ['mode' => TaskMode::Fix->value, 'resumed_ci' => true], task: $task);

                $this->runOrReleaseClaim($task, $original, function () use ($task, $ciResume, $original, $isTimeBounded): void {
                    if ($ciResume['pending'] === 0 && $ciResume['failed'] === 0) {
                        ProcessCIResultJob::dispatch($task, true, null, $ciResume['runs'][0]['head_sha']);

                        return;
                    }

                    if ($ciResume['pending'] === 0 && ! $this->rerunFailedRuns($task, $ciResume['runs'], $isTimeBounded)) {
                        $this->fallBackToBranchRetry($task, $original);
                    }
                });
            },
        ];
    }

    /**
     * GitHub will not rerun these runs, so the agent continues on the branch
     * instead. The task is still claimed as AwaitingCi, so only the request
     * that holds the claim gets here, and it changes the state under the lock.
     *
     * @param  array<string, mixed>  $original
     */
    private function fallBackToBranchRetry(YakTask $task, array $original): void
    {
        $failureOutput = $original['error_log'] ?? null;

        $isStillWaiting = $this->locked($task, function (YakTask $locked) use ($original): bool {
            if ($locked->status !== TaskStatus::AwaitingCi) {
                return false;
            }

            $attempts = (int) $original['attempts'];
            $locked->update([
                'status' => TaskStatus::Retrying,
                'attempts' => $attempts + 1,
                'attempts_at_manual_retry' => $attempts,
                'error_log' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);

            TaskLogger::info($locked, 'GitHub cannot rerun the failed jobs, continuing on the existing branch', ['branch' => $locked->branch_name]);

            return true;
        });

        if ($isStillWaiting) {
            RetryYakJob::dispatch($task, $failureOutput);
        }
    }

    /**
     * Runs the step that follows a claim. When it fails, the task goes back to
     * the state it was claimed from, so a repeat can try again, and the caller
     * gets a 503 with a sentence instead of a task stuck waiting for a job
     * that was never queued.
     *
     * @param  array<string, mixed>  $original
     */
    private function runOrReleaseClaim(YakTask $task, array $original, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            report($e);
            DB::table('tasks')
                ->where('id', $task->id)
                ->whereIn('status', [TaskStatus::AwaitingCi->value, TaskStatus::Retrying->value])
                ->update($original + ['updated_at' => now()]);

            TaskLogger::warning($task, 'Could not restart the task, it stays failed');

            throw new HttpException(503, 'Yak could not restart this task. Try again in a moment.');
        }
    }

    /**
     * @param  list<array{id: int, head_sha: string, status: ?string, conclusion: ?string}>  $runs
     * @return bool false when GitHub refuses every rerun in a way that repeats; once one rerun is accepted the
     *              refused runs are skipped, because the accepted run reports back through the webhook; a passing failure throws
     */
    private function rerunFailedRuns(YakTask $task, array $runs, bool $isTimeBounded): bool
    {
        $repository = Repository::where('slug', $task->repo)->firstOrFail();
        $installationId = (int) config('yak.channels.github.installation_id');
        $gitHub = app(GitHubAppService::class);
        $isAccepted = false;
        $isRefused = false;

        foreach ($runs as $run) {
            $isFailed = ! GitHubAppService::isUnfinishedWorkflowRun($run) && ! in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true);

            if (! $isFailed) {
                continue;
            }

            $response = $gitHub->rerunFailedJobsResponse($installationId, $repository->github_full_name, $run['id'], $isTimeBounded);

            if (GitHubAppService::isLastingRefusal($response)) {
                $isRefused = true;

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('GitHub could not rerun the failed jobs of run ' . $run['id'] . ' (' . $response->status() . ')');
            }

            $isAccepted = true;
        }

        return $isAccepted || ! $isRefused;
    }

    /**
     * Thread replies queued while the failed run was going would otherwise
     * wait for a success that never came. A fresh start folds them into the
     * description so this run does them too. Setup and review runs do not
     * read the description, so their replies stay queued.
     */
    private function withQueuedReplies(YakTask $task, TaskMode $mode): string
    {
        if (in_array($mode, [TaskMode::Setup, TaskMode::Review], true)) {
            return $task->description;
        }

        $replies = PendingSteeringMessage::drainFor($task);

        if ($replies === null) {
            return $task->description;
        }

        return trim((string) $task->description) . "\n\nReplies added in the thread since this request:\n\n" . $replies;
    }

    /**
     * Whether the task's branch already carries pushed work worth keeping.
     *
     * CI only reports on a branch Yak pushed, so a recorded CI result means
     * the commits are on the remote. Starting over would discard them and
     * cut a new `-2` branch, so these tasks resume on the branch instead.
     */
    private function hasPushedBranch(YakTask $task): bool
    {
        return $task->mode === TaskMode::Fix
            && ! $task->targets_external_pr
            && $task->branch_name !== null
            && $task->logs()->where('message', ProcessCIResultJob::RESULT_LOG_MESSAGE)->exists();
    }

    /**
     * A task that failed only because yak:timeout-ci gave up already has its
     * work pushed, so retrying it goes back to CI instead of the agent. The
     * branch's latest CI result on GitHub is used when it is in: green opens
     * the PR, red re-runs the failed jobs so the result arrives through the
     * webhook as usual. A run that is still going is simply waited for.
     * This only reads; the claim reruns the failed jobs once it has won.
     * Returns null, leaving the task untouched, when GitHub cannot say.
     *
     * @return array{runs: list<array{id: int, head_sha: string, status: ?string, conclusion: ?string}>, pending: int, failed: int}|null
     */
    private function readCiAfterTimeout(YakTask $task, bool $isTimeBounded): ?array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $repository = Repository::where('slug', $task->repo)->first();

        if ($task->mode !== TaskMode::Fix
            || $task->targets_external_pr
            || $task->branch_name === null
            || $task->pr_url !== null
            || ! str_starts_with((string) $task->error_log, TimeoutAwaitingCiCommand::TIMEOUT_ERROR_PREFIX)
            || $installationId === 0
            || $repository === null
            || $repository->settings()->ciSystem() !== 'github_actions') {
            return null;
        }

        $gitHub = app(GitHubAppService::class);

        try {
            $runs = $gitHub->latestCommitWorkflowRunsForBranch($installationId, $repository->github_full_name, $task->branch_name, $isTimeBounded);
        } catch (Throwable $e) {
            Log::channel('yak')->warning('Could not read CI runs for a dashboard retry', ['task_id' => $task->id, 'error' => $e->getMessage()]);

            return null;
        }

        if ($runs === []) {
            return null;
        }

        $pending = array_filter($runs, fn (array $run): bool => GitHubAppService::isUnfinishedWorkflowRun($run));
        $failed = array_filter($runs, fn (array $run): bool => ! GitHubAppService::isUnfinishedWorkflowRun($run)
            && ! in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true));

        return ['runs' => $runs, 'pending' => count($pending), 'failed' => count($failed)];
    }
}
