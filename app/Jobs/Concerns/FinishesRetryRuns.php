<?php

namespace App\Jobs\Concerns;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\TaskStop;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Services\ArtifactPersister;
use App\Services\IncusSandboxManager;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\PullRequests\ThreadRepositoryPullRequests;
use App\Services\YakPersonality;
use Illuminate\Support\Facades\Log;
use Throwable;

trait FinishesRetryRuns
{
    use CompletesTeleportTurns;

    private function finishRetryResult(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $containerName, RunRecorder $recorder): void
    {
        if ($result->isError) {
            TaskMetricsAccumulator::record($this->task, $result);
            $this->handleError($result->failureMessage(), stop: $result->stop());

            return;
        }

        if ($result->wrongRepository && $this->handleWrongRepository($repository, $result, countsAsNewTask: false)) {
            return;
        }

        SandboxArtifactCollector::exclusive($this->task, function () use ($sandbox, $containerName): void {
            SandboxArtifactCollector::collect($sandbox, $containerName, $this->task);
            ArtifactPersister::persist($this->task);
        });

        $this->handleSuccess($repository, $result, $sandbox, $containerName, $recorder);
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $containerName, RunRecorder $recorder): void
    {
        TaskMetricsAccumulator::record($this->task, $result);
        // The task was counted the day its first run finished.
        DailyCost::accumulate($result->costUsd, newTask: false);

        if ($this->isUnpublishedTeleportTask()) {
            $this->completeTeleportTurn($result, $recorder);

            return;
        }

        $workspacePath = IncusSandboxManager::workspacePath();

        if ($this->task->branch_name !== null) {
            // Safety check
            $branchResult = $sandbox->run($containerName, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10);
            $currentBranch = trim($branchResult->output());

            if ($currentBranch === $repository->default_branch) {
                throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
            }

            $hasCommits = GitOperations::hasNewCommits($sandbox, $containerName, $workspacePath, $repository->default_branch);

            if (! $hasCommits) {
                app(ThreadRepositoryPullRequests::class)->publishForTask($this->task);

                if (GitOperations::hasUncommittedChanges($sandbox, $containerName, $workspacePath)) {
                    throw new \RuntimeException(
                        'Agent finished retry with uncommitted changes -- run `git add -A && git commit` before returning.'
                    );
                }

                $recorder->noChanges();

                $this->task->update([
                    'status' => TaskStatus::Success,
                    'completed_at' => now(),
                    'result_summary' => $result->resultSummary,
                    'pr_body_update' => null,
                    'model_used' => config('yak.default_model'),
                ]);

                TaskLogger::info($this->task, 'Answered without code changes (retry)');

                $message = YakPersonality::generate(NotificationType::Result, $result->resultSummary);
                SendNotificationJob::dispatch($this->task, NotificationType::Result, $message);

                return;
            }

            if ($repository->settings()->ciSystem() !== 'none' && ! $this->branchDiffersFromRemote($sandbox, $containerName, $workspacePath)) {
                $this->rerunCiOnUnchangedBranch($repository, $result);

                return;
            }

            // Counted against the branch as it was fetched; the rebase above
            // rewrites hashes, so the commit count can include replayed ones.
            $recorder->gitStats(GitOperations::changeStats($sandbox, $containerName, $workspacePath, "origin/{$this->task->branch_name}"));

            $this->task->update($this->postAgentUpdate($repository, $result));

            // Force push from sandbox (retry overwrites the previous attempt)
            $pushResult = $sandbox->run($containerName, "cd {$workspacePath} && git push --force-with-lease origin {$this->task->branch_name}", timeout: 60);

            if ($pushResult->exitCode() !== 0) {
                throw new \RuntimeException("Git push failed in sandbox: {$pushResult->errorOutput()}");
            }

            app(ThreadRepositoryPullRequests::class)->publishForTask($this->task);

            $recorder->mark('post_agent');

            TaskLogger::info($this->task, 'Change pushed -- retry', ['branch' => $this->task->branch_name]);
        } else {
            $this->task->update($this->postAgentUpdate($repository, $result));
        }

        if ($repository->settings()->ciSystem() === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed retry on branch {$this->task->branch_name} -- waiting for CI to finish before opening a PR.");
            SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
        }
    }

    private function branchDiffersFromRemote(SandboxShell $sandbox, string $containerName, string $workspacePath): bool
    {
        $diff = $sandbox->run($containerName, "cd {$workspacePath} && git rev-list --count origin/{$this->task->branch_name}...HEAD", timeout: 15);

        return $diff->exitCode() !== 0 || (int) trim($diff->output()) > 0;
    }

    /**
     * The agent left the branch as CI last saw it, so a push starts no new CI
     * run. The failed GitHub Actions jobs on that commit run again instead, and
     * the task fails now when they cannot, so it never waits on a CI result
     * that is not coming.
     */
    private function rerunCiOnUnchangedBranch(Repository $repository, AgentRunResult $result): void
    {
        $this->task->update($this->postAgentUpdate($repository, $result));

        // A branch whose newest commit already passed CI and has no pull
        // request gets one now: no CI event is coming for an unchanged head.
        if ($this->task->pr_url === null && $this->hasOnlyPassingCiRuns($repository)) {
            TaskLogger::info($this->task, 'No new changes and CI already passed -- opening the pull request', ['branch' => $this->task->branch_name]);
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();

            return;
        }

        if (! $this->rerunFailedCiJobs($repository)) {
            $this->handleError('Retry made no changes to the branch, so CI could not run again on it.');

            return;
        }

        TaskLogger::info($this->task, 'No new changes -- rerunning failed CI jobs', ['branch' => $this->task->branch_name]);

        $message = YakPersonality::generate(NotificationType::Progress, "Retry made no changes on {$this->task->branch_name} -- rerunning the failed CI jobs before opening a PR.");
        SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
    }

    private function hasOnlyPassingCiRuns(Repository $repository): bool
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($repository->settings()->ciSystem() !== 'github_actions' || $installationId === 0) {
            return false;
        }

        try {
            $runs = app(GitHubAppService::class)->latestCommitWorkflowRunsForBranch($installationId, $repository->github_full_name, (string) $this->task->branch_name);
        } catch (Throwable) {
            return false;
        }

        return $runs !== [] && array_filter(
            $runs,
            fn (array $run): bool => GitHubAppService::isUnfinishedWorkflowRun($run) || ! in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true),
        ) === [];
    }

    private function rerunFailedCiJobs(Repository $repository): bool
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($repository->settings()->ciSystem() !== 'github_actions' || $installationId === 0) {
            return false;
        }

        $gitHub = app(GitHubAppService::class);

        try {
            $failedRuns = array_filter(
                $gitHub->latestCommitWorkflowRunsForBranch($installationId, $repository->github_full_name, (string) $this->task->branch_name),
                fn (array $run): bool => ! GitHubAppService::isUnfinishedWorkflowRun($run)
                    && ! in_array($run['conclusion'], ['success', 'skipped', 'neutral'], true),
            );

            if ($failedRuns === []) {
                return false;
            }

            foreach ($failedRuns as $run) {
                if (! $gitHub->rerunFailedJobs($installationId, $repository->github_full_name, $run['id'])) {
                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::channel('yak')->warning('Could not rerun failed CI jobs after an unchanged retry', ['task_id' => $this->task->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function postAgentUpdate(Repository $repository, AgentRunResult $result): array
    {
        $update = [
            'result_summary' => $result->resultSummary,
            // A retry replaces the failed attempt's rewritten description
            // wholesale, so any pr_body_update it left behind must not
            // survive to be published by
            // CreatePullRequestJob::refreshOwnedSections() on this retry's
            // green CI. review_replies survives: the retry keeps the
            // follow-up's commits and only rebases, so the replies still
            // answer the reviewer's comments correctly.
            'pr_body_update' => null,
            'model_used' => config('yak.default_model'),
        ];

        if ($repository->settings()->ciSystem() !== 'none') {
            $update['status'] = TaskStatus::AwaitingCi;
        }

        return $update;
    }

    private function handleError(string $errorMessage, ?TaskStop $stop = null): void
    {
        // Don't overwrite a task that's already terminal -- see
        // RunYakJob::handleError() for the full reasoning.
        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);

        $this->notifySourceOfFailure($errorMessage, $stop);
    }
}
