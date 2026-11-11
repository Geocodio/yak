<?php

namespace App\Jobs\Concerns;

use App\Agents\ClaudeCodeOutputParser;
use App\Channels\Slack\PreviewRepairMessenger;
use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\PreviewRepairVerdict;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\DailyCost;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ArtifactPersister;
use App\Services\IncusSandboxManager;
use App\Services\PreviewRepair\PreviewFailureReporter;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\PullRequests\ThreadPullRequestSync;
use App\Services\Threads\PullRequests\ThreadRepositoryPullRequests;
use App\Services\YakPersonality;
use Illuminate\Support\Facades\Log;

/**
 * What happens after an agent run has returned its result: error handling,
 * the wrong-repository and clarification branches, artifact collection, the
 * push and the hand-off to CI. Shared by the after-turn jobs that finish
 * a turn which may push.
 *
 * The consuming job must expose a `$task` property typed to YakTask and use
 * AsksClarifyingQuestions, HandlesWrongRepository and NotifiesSourceOfFailure.
 *
 * @property YakTask $task
 */
trait FinishesAgentRuns
{
    use CompletesTeleportTurns;
    use GuardsTerminalTaskStatus;

    /**
     * Turns a returned result into the task's next state. Records the run's
     * metrics exactly once, whichever branch it takes.
     */
    private function finishAgentResult(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void
    {
        if ($result->isError) {
            TaskMetricsAccumulator::record($this->task, $result);

            $errorMessage = $result->failureMessage();

            Log::channel('yak')->error('Agent error details', [
                'task_id' => $this->task->id,
                'error' => $errorMessage,
                'num_turns' => $result->numTurns,
                'cost_usd' => $result->costUsd,
                'duration_ms' => $result->durationMs,
            ]);

            $this->handleError($errorMessage);

            return;
        }

        TaskLogger::info($this->task, 'Assessment complete');

        if ($result->wrongRepository && $this->handleWrongRepository($repository, $result)) {
            return;
        }

        if ($this->askIfNeeded($result)) {
            return;
        }

        // Questions ignored at the round limit must not reach the PR body or reply.
        $result = $result->withResultSummary(ClaudeCodeOutputParser::stripClarificationBlock($result->resultSummary));

        // Pull .yak-artifacts/ out of the sandbox, then turn the files into
        // Artifact rows and start the render so they are visible before CI ends.
        SandboxArtifactCollector::collect($shell, $container, $this->task);
        ArtifactPersister::persist($this->task);

        $this->handleSuccess($repository, $result, $shell, $container, $recorder);
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void
    {
        TaskMetricsAccumulator::record($this->task, $result);
        DailyCost::accumulate($result->costUsd);

        if ($this->isUnpublishedTeleportTask()) {
            $this->completeTeleportTurn($result, $recorder);

            return;
        }

        $workspacePath = IncusSandboxManager::workspacePath();

        if ($this->task->branch_name !== null) {
            // Safety check: verify not on default branch
            $branchResult = $shell->run($container, "cd {$workspacePath} && git rev-parse --abbrev-ref HEAD", timeout: 10);
            $currentBranch = trim($branchResult->output());

            if ($currentBranch === $repository->default_branch) {
                throw new \RuntimeException("Sandbox is on the default branch '{$currentBranch}'. Refusing to push.");
            }

            $hasCommits = GitOperations::hasNewCommits($shell, $container, $workspacePath, $repository->default_branch);

            if (! $hasCommits) {
                app(ThreadRepositoryPullRequests::class)->publishForTask($this->task);
            }

            if ($this->task->source === PreviewFailureReporter::SOURCE && ! $this->acceptsPreviewRepairFix($result, $hasCommits)) {
                $this->completeWithoutChanges($result, $recorder);

                return;
            }

            if (! $hasCommits) {
                // Dirty tree + no commits means the agent edited files but
                // forgot to `git add && git commit` before returning. Treat
                // as a real failure so the retry path (RetryYakJob) picks it
                // up with a clearer hint -- otherwise the work silently
                // disappears and the task claims success.
                if (GitOperations::hasUncommittedChanges($shell, $container, $workspacePath)) {
                    throw new \RuntimeException(
                        'Agent finished with uncommitted changes, run `git add -A && git commit` before returning.'
                    );
                }

                // Clean tree + no commits: the agent legitimately answered
                // without writing code. Artifacts were already persisted
                // in the main handle() flow above. Skip push + CI.
                $this->completeWithoutChanges($result, $recorder);

                return;
            }

            $recorder->gitStats(GitOperations::changeStats($shell, $container, $workspacePath, "origin/{$repository->default_branch}"));

            $this->task->update($this->postAgentUpdate($repository, $result));

            $pushResult = $shell->run($container, "cd {$workspacePath} && git push origin {$this->task->branch_name}", timeout: 60);

            if ($pushResult->exitCode() !== 0) {
                throw new \RuntimeException("Git push failed in sandbox: {$pushResult->errorOutput()}");
            }

            app(ThreadRepositoryPullRequests::class)->publishForTask($this->task);

            $recorder->mark('post_agent');
            TaskLogger::info($this->task, 'Fix pushed', ['branch' => $this->task->branch_name]);
        } else {
            $this->task->update($this->postAgentUpdate($repository, $result));
        }

        if ($repository->settings()->ciSystem() === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed fix on branch {$this->task->branch_name}, waiting for CI to finish before opening a PR.");
            SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
        }
    }

    private function completeWithoutChanges(AgentRunResult $result, RunRecorder $recorder): void
    {
        $recorder->noChanges();

        $this->task->update([
            'status' => TaskStatus::Success,
            'completed_at' => now(),
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
        ]);

        TaskLogger::info($this->task, 'Answered without code changes');

        $message = YakPersonality::generate(NotificationType::Result, $result->resultSummary);
        SendNotificationJob::dispatch($this->task, NotificationType::Result, $message);
    }

    /**
     * Records a preview repair task's verdict and says whether its commits
     * become a pull request.
     *
     * Only a broken shared recipe with a committed fix goes on to push. Any
     * other verdict, a missing verdict, or a recipe verdict without commits
     * ends here: commits made anyway stay unpushed, and the messenger tells
     * the right people what the investigation found.
     */
    private function acceptsPreviewRepairFix(AgentRunResult $result, bool $hasCommits): bool
    {
        $parsed = ClaudeCodeOutputParser::extractPreviewRepairVerdict($result->resultSummary);
        $verdict = $parsed['verdict'] === PreviewRepairVerdict::Recipe && ! $hasCommits
            ? PreviewRepairVerdict::Unknown
            : $parsed['verdict'];

        $context = json_decode((string) $this->task->context, true);
        $previewRepairId = is_array($context) && is_int($context['preview_repair_id'] ?? null) ? $context['preview_repair_id'] : null;
        $previewRepair = $previewRepairId !== null ? PreviewRepair::find($previewRepairId) : null;
        $previewRepair?->update(['verdict' => $verdict, 'cause' => $parsed['cause'] !== '' ? $parsed['cause'] : null]);

        if ($verdict === PreviewRepairVerdict::Recipe) {
            return true;
        }

        if ($hasCommits) {
            TaskLogger::info($this->task, 'Dropped commits: the preview repair verdict is not a broken recipe', ['verdict' => $verdict->value]);
        }

        if ($previewRepair !== null) {
            app(PreviewRepairMessenger::class)->verdictReached($previewRepair);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function postAgentUpdate(Repository $repository, AgentRunResult $result): array
    {
        $update = [
            'result_summary' => $result->resultSummary,
            'model_used' => config('yak.default_model'),
        ];

        // For repos with real CI, park the task in AwaitingCi until the
        // webhook/poll flips it. For ci_system=none there's nothing to wait
        // for; leave the status as Running so ProcessCIResultJob can move
        // straight to Success -- and, if PR creation blows up, the failed()
        // recovery path moves it to Failed instead of stranding it in a
        // misleading "awaiting_ci" state on a repo with no CI.
        if ($repository->settings()->ciSystem() !== 'none') {
            $update['status'] = TaskStatus::AwaitingCi;
            app(ThreadPullRequestSync::class)->recordPrimaryCiStatus($this->task, 'running');
        }

        return $update;
    }

    private function handleError(string $errorMessage): void
    {
        // Don't overwrite a task that's already terminal -- cancelled
        // mid-run, force-failed by a deploy drain with an accurate
        // reason, or finalised by a parallel path. Overwriting it here
        // would replace that reason with whatever this run's own error
        // happened to be (often just the abnormal-termination artifact of
        // whatever interrupted it).
        if ($this->taskIsTerminal($this->task->fresh())) {
            return;
        }

        $this->task->update([
            'status' => TaskStatus::Failed,
            'error_log' => $errorMessage,
            'completed_at' => now(),
        ]);

        TaskLogger::error($this->task, 'Task failed', ['error' => $errorMessage]);

        $this->notifySourceOfFailure($errorMessage);
    }
}
