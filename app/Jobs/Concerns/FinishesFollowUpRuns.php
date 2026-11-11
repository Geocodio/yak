<?php

namespace App\Jobs\Concerns;

use App\Agents\ClaudeCodeOutputParser;
use App\Channels\GitHub\PullRequestSummonReplier;
use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\ParsedFollowUpSummary;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\GitOperations;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Services\ArtifactPersister;
use App\Services\FollowUpSummaryParser;
use App\Services\IncusSandboxManager;
use App\Services\ReviewReplyPoster;
use App\Services\SandboxArtifactCollector;
use App\Services\TaskLogger;
use App\Services\TaskMetricsAccumulator;
use App\Services\Telemetry\RunRecorder;
use App\Services\YakPersonality;
use Illuminate\Support\Facades\Log;

trait FinishesFollowUpRuns
{
    private function finishFollowUpResult(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $containerName, RunRecorder $recorder): void
    {
        if ($result->isError) {
            TaskMetricsAccumulator::record($this->task, $result);
            $this->handleError($result->failureMessage());

            return;
        }

        if ($this->askIfNeeded($result)) {
            return;
        }

        // Questions ignored at the round limit must not reach the output.
        $result = $result->withResultSummary(ClaudeCodeOutputParser::stripClarificationBlock($result->resultSummary));

        SandboxArtifactCollector::collect($sandbox, $containerName, $this->task);
        ArtifactPersister::persist($this->task);

        $this->handleSuccess($repository, $result, $sandbox, $containerName, $recorder);
    }

    private function handleSuccess(Repository $repository, AgentRunResult $result, SandboxShell $sandbox, string $containerName, RunRecorder $recorder): void
    {
        TaskMetricsAccumulator::record($this->task, $result);

        $parsed = app(FollowUpSummaryParser::class)->parse($result->resultSummary);

        DailyCost::accumulate($result->costUsd);

        $branchName = $this->task->branch_name;

        if ($branchName === null) {
            throw new \RuntimeException('Follow-up reached the push step with no branch name.');
        }

        if ($this->task->targets_external_pr) {
            $this->finishExternalPr($repository, $parsed, $sandbox, $containerName, $branchName, $recorder);

            return;
        }

        $recorder->gitStats(GitOperations::changeStats($sandbox, $containerName, IncusSandboxManager::workspacePath(), "origin/{$branchName}"));

        if (! $this->hasNewCommits($sandbox, $containerName, $branchName)) {
            // The follow-up prompt allows answering a question without
            // changing code. No commits means there's nothing to push or
            // wait on CI for -- resolve the task right away instead of
            // parking it in AwaitingCi for a check_suite that never comes.
            // When the answers already live on their threads, the summary is
            // dropped so no "pushed changes" comment follows them.
            $this->task->update([
                'result_summary' => $parsed->changes !== '' && $parsed->replies === [] ? $parsed->changes : null,
                'pr_body_update' => $parsed->description,
                'review_replies' => $parsed->replies !== [] ? $parsed->replies : null,
                'model_used' => config('yak.default_model'),
            ]);

            TaskLogger::info($this->task, 'Follow-up produced no commits; skipping push');

            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();

            return;
        }

        $update = [
            'result_summary' => $parsed->changes !== '' ? $parsed->changes : null,
            'pr_body_update' => $parsed->description,
            'review_replies' => $parsed->replies !== [] ? $parsed->replies : null,
            'model_used' => config('yak.default_model'),
        ];

        $ciSystem = $repository->settings()->ciSystem();

        if ($ciSystem !== 'none') {
            $update['status'] = TaskStatus::AwaitingCi;
        }

        $this->task->update($update);

        $this->pushExistingBranch($sandbox, $containerName, $repository, $branchName);
        $recorder->mark('post_agent');
        TaskLogger::info($this->task, 'Follow-up pushed', ['branch' => $branchName]);

        if ($ciSystem === 'none') {
            ProcessCIResultJob::dispatch($this->task, passed: true)->afterCommit();
        } else {
            $message = YakPersonality::generate(NotificationType::Progress, "Pushed your changes on branch {$branchName} -- waiting for CI before updating the PR.");
            SendNotificationJob::dispatch($this->task, NotificationType::Progress, $message);
        }
    }

    /**
     * A person owns this branch: push onto it, answer in the summoning thread,
     * and stop. No CI wait, no retry, and the PR description is theirs.
     */
    private function finishExternalPr(Repository $repository, ParsedFollowUpSummary $parsed, SandboxShell $sandbox, string $containerName, string $branchName, RunRecorder $recorder): void
    {
        $recorder->gitStats(GitOperations::changeStats($sandbox, $containerName, IncusSandboxManager::workspacePath(), "origin/{$branchName}"));

        $this->task->update([
            'result_summary' => $parsed->changes !== '' ? $parsed->changes : null,
            'review_replies' => $parsed->replies !== [] ? $parsed->replies : null,
            'model_used' => config('yak.default_model'),
        ]);

        $pushedSha = null;

        if ($this->hasNewCommits($sandbox, $containerName, $branchName)) {
            $this->pushOntoExternalBranch($sandbox, $containerName, $repository, $branchName);
            $recorder->mark('post_agent');
            TaskLogger::info($this->task, 'Pushed onto the PR branch', ['branch' => $branchName]);

            $headSha = trim($sandbox->run($containerName, 'cd ' . IncusSandboxManager::workspacePath() . ' && git rev-parse HEAD', timeout: 10)->output());
            $pushedSha = preg_match('/^[0-9a-f]{40}$/', $headSha) === 1 ? $headSha : null;
        }

        try {
            $postedReplyCount = app(ReviewReplyPoster::class)->post($this->task, $repository->github_full_name, (int) $this->task->pr_number);

            if ($postedReplyCount === 0) {
                $summary = $parsed->changes !== '' ? $parsed->changes : 'Done. I made no changes.';

                if ($pushedSha !== null) {
                    $summary .= "\n\nPushed " . substr($pushedSha, 0, 7) . ": https://github.com/{$repository->github_full_name}/commit/{$pushedSha}";
                }

                app(PullRequestSummonReplier::class)->replyForTask($this->task, $summary, quoteSummon: true);
            }
        } catch (\Throwable $replyError) {
            TaskLogger::warning($this->task, 'Summon reply could not be posted', ['error' => $replyError->getMessage()]);
        }

        $this->task->update(['status' => TaskStatus::Success, 'completed_at' => now()]);
    }

    /**
     * Whether the sandbox's HEAD has commits the follow-up branch's remote
     * doesn't have yet. A failed command or a non-numeric result (an
     * unexpected command output) is treated as unknown and defaults to
     * true, so the safer, existing push-and-wait path runs rather than
     * silently dropping work.
     */
    private function hasNewCommits(SandboxShell $sandbox, string $containerName, string $branchName): bool
    {
        $workspacePath = IncusSandboxManager::workspacePath();

        $result = $sandbox->run(
            $containerName,
            "cd {$workspacePath} && git rev-list --count origin/{$branchName}..HEAD",
            timeout: 15,
        );

        if ($result->exitCode() !== 0) {
            return true;
        }

        $output = trim($result->output());

        // An empty result and a clean integer both parse safely with
        // (int) casting; anything else (a git error, unexpected text) is
        // treated as unknown and defaults to the existing push-and-wait
        // path rather than silently dropping work.
        if ($output !== '' && ! ctype_digit($output)) {
            return true;
        }

        return (int) $output > 0;
    }

    /**
     * $isUserFacing marks a message written for the PR author. Every other
     * message may carry command lines or credentials, so it stays in the task
     * log and the PR gets a generic line.
     */
    private function handleError(string $errorMessage, bool $isUserFacing = false): void
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

        TaskLogger::error($this->task, 'Follow-up failed', ['error' => $errorMessage]);

        // The failure reply goes to the summoning thread for an external PR;
        // a second error notification would comment on the PR again.
        if (! $this->task->targets_external_pr) {
            $this->notifySourceOfFailure($errorMessage);
        }

        if ($this->task->targets_external_pr) {
            try {
                $reply = $isUserFacing ? $errorMessage : "I couldn't finish this. The details are on the task page.";

                app(PullRequestSummonReplier::class)->replyForTask($this->task, $reply);
            } catch (\Throwable $replyError) {
                TaskLogger::warning($this->task, 'Failure reply could not be posted', ['error' => $replyError->getMessage()]);
            }
        }
    }
}
