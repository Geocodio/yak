<?php

namespace App\Http\Controllers\Tasks;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Console\Commands\TimeoutAwaitingCiCommand;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Events\TaskStatusChanged;
use App\Facades\Telemetry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\RerouteTaskRequest;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\ResearchFollowUpJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\PendingSteeringMessage;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\IncusSandboxManager;
use App\Services\TaskLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaskActionController extends Controller
{
    public function retry(YakTask $task): RedirectResponse
    {
        if (! in_array($task->status, [TaskStatus::Failed, TaskStatus::Expired], true)) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task cannot be retried right now.');
        }

        if ($this->resumeCiAfterTimeout($task)) {
            return redirect()->route('tasks.show', $task)->with('success', "Checking CI on {$task->branch_name} again.");
        }

        if ($this->hasPushedBranch($task)) {
            return $this->retryOnExistingBranch($task);
        }

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

        Telemetry::feature('dashboard.retry', ['mode' => $mode->value], task: $task);

        app(AgentJobDispatcher::class)->dispatch($task, $jobClass);

        return redirect()->route('tasks.show', $task)->with('success', 'Task re-queued.');
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
     * Returns false, leaving the task untouched, when GitHub cannot say.
     */
    private function resumeCiAfterTimeout(YakTask $task): bool
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
            return false;
        }

        $gitHub = app(GitHubAppService::class);

        try {
            $runs = $gitHub->latestCommitWorkflowRunsForBranch($installationId, $repository->github_full_name, $task->branch_name);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Could not read CI runs for a dashboard retry', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($runs === []) {
            return false;
        }

        $pending = array_filter($runs, fn (array $run): bool => GitHubAppService::isUnfinishedWorkflowRun($run));
        $failed = array_filter($runs, fn (array $run): bool => ! GitHubAppService::isUnfinishedWorkflowRun($run)
            && ! in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true));

        if ($pending === [] && $failed !== []) {
            foreach ($failed as $run) {
                if (! $gitHub->rerunFailedJobs($installationId, $repository->github_full_name, $run['id'])) {
                    return false;
                }
            }
        }

        $task->update(['status' => TaskStatus::Retrying]);
        $task->update([
            'status' => TaskStatus::AwaitingCi,
            'error_log' => null,
            'completed_at' => null,
        ]);

        TaskLogger::info($task, TimeoutAwaitingCiCommand::CI_RESUMED_LOG_MESSAGE, [
            'branch' => $task->branch_name,
            'still_running' => count($pending),
            'rerun_failed' => $pending === [] ? count($failed) : 0,
        ]);
        Telemetry::feature('dashboard.retry', ['mode' => TaskMode::Fix->value, 'resumed_ci' => true], task: $task);

        if ($pending === [] && $failed === []) {
            ProcessCIResultJob::dispatch($task, true, null, $runs[0]['head_sha']);
        }

        return true;
    }

    /**
     * Run a CI retry on the existing branch, the same pass Yak takes after a
     * red build: the agent gets the previous summary and the CI failure, and
     * pushes on top of the earlier commits.
     */
    private function retryOnExistingBranch(YakTask $task): RedirectResponse
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

        TaskLogger::info($task, 'Retry requested from the dashboard, continuing on the existing branch', [
            'branch' => $task->branch_name,
        ]);
        Telemetry::feature('dashboard.retry', ['mode' => TaskMode::Fix->value, 'resumed_branch' => true], task: $task);

        RetryYakJob::dispatch($task, $failureOutput);

        return redirect()->route('tasks.show', $task)->with('success', "Retrying on {$task->branch_name}.");
    }

    public function cancel(Request $request, YakTask $task): RedirectResponse
    {
        $cancellable = in_array($task->status, [
            TaskStatus::Pending,
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ], true);

        if (! $cancellable) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task cannot be cancelled right now.');
        }

        TaskLogger::info($task, 'Task cancelled by user');
        Telemetry::feature('dashboard.cancel', ['status' => $task->status->value], task: $task);

        $containerName = 'task-' . $task->id;

        try {
            app(IncusSandboxManager::class)->destroy($containerName);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('Sandbox destroy failed during cancel', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }

        $task->update([
            'status' => TaskStatus::Cancelled,
            'completed_at' => now(),
        ]);

        $canceller = $request->user();

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

        return redirect()->route('tasks.show', $task)->with('success', 'Task cancelled.');
    }

    public function rerunReview(YakTask $task, GitHubAppService $github): RedirectResponse
    {
        if ($task->mode !== TaskMode::Review) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task is not a review.');
        }

        if (in_array($task->status, [TaskStatus::Pending, TaskStatus::Running], true)) {
            return redirect()->route('tasks.show', $task)->with('error', 'A review is already queued for this PR.');
        }

        $installationId = (int) config('yak.channels.github.installation_id');
        $oldContext = json_decode((string) $task->context, true) ?: [];
        $prNumber = $oldContext['pr_number'] ?? null;

        if ($prNumber === null) {
            return redirect()->route('tasks.show', $task)->with('error', 'Cannot determine PR number.');
        }

        $prPayload = $github->getPullRequest($installationId, $task->repo, (int) $prNumber);

        if (! isset($prPayload['head']['sha'])) {
            return redirect()->route('tasks.show', $task)->with('error', 'Failed to fetch PR from GitHub.');
        }

        PrReview::where('yak_task_id', $task->id)->delete();

        /** @var TaskStatus $statusBefore */
        $statusBefore = $task->status;

        // Raw update because Success is a final state the enum will not
        // transition out of. Lifetime cost/turn totals are kept on purpose.
        DB::table('tasks')->where('id', $task->id)->update([
            'status' => TaskStatus::Pending->value,
            'error_log' => null,
            'result_summary' => null,
            'pr_body_update' => null,
            'review_replies' => null,
            'started_at' => null,
            'completed_at' => null,
            'branch_name' => (string) $prPayload['head']['ref'],
            'context' => json_encode([
                'pr_number' => (int) $prPayload['number'],
                'head_sha' => (string) $prPayload['head']['sha'],
                'head_ref' => (string) $prPayload['head']['ref'],
                'base_sha' => (string) $prPayload['base']['sha'],
                'base_ref' => (string) $prPayload['base']['ref'],
                'author' => (string) ($prPayload['user']['login'] ?? ''),
                'title' => (string) ($prPayload['title'] ?? ''),
                'body' => (string) ($prPayload['body'] ?? ''),
                'review_scope' => 'full',
                'incremental_base_sha' => null,
            ]),
            'updated_at' => now(),
        ]);

        $task->refresh();

        TaskStatusChanged::dispatch($task, $statusBefore, TaskStatus::Pending);
        Telemetry::feature('dashboard.rerun_review', [], task: $task);

        app(AgentJobDispatcher::class)->dispatch($task, RunYakReviewJob::class);

        return redirect()->route('tasks.show', $task)->with('success', 'Re-running review for this PR.');
    }

    public function retryRender(YakTask $task): RedirectResponse
    {
        $rawFootage = $task->artifacts()->rawFootage()->latest('id')->first();

        if ($rawFootage === null) {
            return redirect()->route('tasks.show', $task)->with('error', 'Nothing to re-render for this task.');
        }

        RenderVideoJob::dispatch($rawFootage->id);

        return redirect()->route('tasks.show', $task)->with('success', 'Re-rendering the walkthrough.');
    }

    public function reroute(RerouteTaskRequest $request, YakTask $task): RedirectResponse
    {
        $canReroute = ! in_array($task->mode, [TaskMode::Setup, TaskMode::Review], true) && $task->pr_url === null;

        if (! $canReroute) {
            return redirect()->route('tasks.show', $task)->with('error', 'This task cannot be moved to another repo.');
        }

        $slug = $request->validated('repo');

        $newRepo = Repository::where('slug', $slug)->where('is_active', true)->first();

        if ($newRepo === null) {
            return redirect()->route('tasks.show', $task)->with('error', 'Repository not found or inactive.');
        }

        $oldRepo = (string) $task->repo;

        if ($newRepo->slug === $oldRepo) {
            return redirect()->route('tasks.show', $task);
        }

        $inFlight = in_array($task->status, [
            TaskStatus::Running,
            TaskStatus::AwaitingClarification,
            TaskStatus::AwaitingCi,
            TaskStatus::Retrying,
        ], true);

        if ($inFlight) {
            try {
                app(IncusSandboxManager::class)->destroy('task-' . $task->id);
            } catch (\Throwable $e) {
                Log::channel('yak')->warning('Sandbox destroy failed during reroute', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        /** @var TaskStatus $statusBefore */
        $statusBefore = $task->status;

        // Raw update: the enum forbids some of these transitions. Lifetime
        // cost/turn totals are kept on purpose.
        DB::table('tasks')->where('id', $task->id)->update([
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

        $task->refresh();

        TaskStatusChanged::dispatch($task, $statusBefore, TaskStatus::Pending);
        Telemetry::feature('dashboard.reroute', ['from' => $oldRepo, 'to' => $newRepo->slug], task: $task);

        TaskLogger::info($task, "Task rerouted from {$oldRepo} to {$newRepo->slug}");

        /** @var TaskMode $mode */
        $mode = $task->mode;

        $jobClass = match ($mode) {
            TaskMode::Research => ResearchYakJob::class,
            default => RunYakJob::class,
        };

        app(AgentJobDispatcher::class)->dispatch($task, $jobClass);

        SendNotificationJob::dispatch(
            $task,
            NotificationType::Retry,
            "Moved from {$oldRepo} to {$newRepo->slug} — restarting there.",
        );

        return redirect()->route('tasks.show', $task)->with('success', "Task moved to {$newRepo->slug}.");
    }
}
